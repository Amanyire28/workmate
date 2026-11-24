<?php

namespace App\Services;

use App\Support\SimplePdf;
use Carbon\Carbon;

/**
 * Phase B9: a provider's financial statements as CSV and PDF. Two separate statements, never mixed:
 *
 *   commission  the ledger between two dates: opening balance, each assessment, reversal and verified settlement, closing balance
 *   payments    what customers paid the provider directly and confirmed (not WorkMate's money), with what is still waiting
 *
 * Every figure comes from ProviderFinance (the same functions the screens use), so a statement can only say what the screen says.
 * Text is formula-neutralised in CSV. Internal notes, audit entries and adjustment requests are not part of any statement.
 */
class ProviderStatements
{
    public const KINDS = ['commission', 'payments'];
    public const FORMATS = ['csv', 'pdf'];

    /** @var ProviderFinance */
    private $finance;

    public function __construct(ProviderFinance $finance)
    {
        $this->finance = $finance;
    }

    private function when(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->setTimezone(Scheduling::TIMEZONE)->format('Y-m-d H:i') : '';
    }

    private function ugx(int $n): string
    {
        return 'UGX '.number_format($n);
    }

    private function typeWords(string $type): string
    {
        return ['assessment' => 'Commission assessed', 'reversal' => 'Commission taken off', 'settlement' => 'Payment to WorkMate verified'][$type] ?? $type;
    }

    public function filename(string $kind, string $format): string
    {
        return 'workmate-'.$kind.'-statement-'.now(Scheduling::TIMEZONE)->format('Ymd-His').'.'.$format;
    }

    /** @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\Response */
    public function download(int $providerId, string $kind, string $format, array $in)
    {
        $p = $this->finance->period($in);
        $business = $this->finance->business($providerId);
        $name = $this->filename($kind, $format);
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($providerId, $kind, $p, $business) {
                $this->writeCsv($providerId, $kind, $p, $business);
            }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }

        return response($this->pdf($providerId, $kind, $p, $business), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private function writeCsv(int $providerId, string $kind, array $p, string $business): void
    {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $row = function (array $cells) use ($out) {
            fputcsv($out, array_map([FinancialReports::class, 'safe'], $cells));
        };
        $row(['# WorkMate '.($kind === 'commission' ? 'commission statement' : 'customer payments statement')]);
        $row(['# Business: '.$business]);
        $row(['# Period: '.$p['label']]);
        $row(['# Generated: '.now(Scheduling::TIMEZONE)->format('Y-m-d H:i').' (Kampala time). Currency: UGX, whole shillings.']);
        if ($kind === 'commission') {
            $t = $this->finance->statementTotals($providerId, $p);
            $row(['# Opening balance (UGX)', $t['opening_ugx']]);
            $row(['# Closing balance (UGX)', $t['closing_ugx']]);
            $row(['Date (Kampala)', 'What', 'Job', 'Confirmed payment (UGX)', 'Rate', 'Settlement', 'Reference', 'Amount (UGX)', 'Balance after (UGX)']);
            foreach ($this->finance->statementLines($providerId, $p) as $l) {
                $row([$this->when($l['at']), $this->typeWords($l['type']), $l['job'], $l['payment_ugx'], $l['rate'], $l['cycle_id'], $l['payment_reference'] ?: $l['reference'], $l['amount_ugx'], $l['balance_ugx']]);
            }
            $row(['# Commission assessed (UGX)', $t['assessed_ugx']]);
            $row(['# Taken off (UGX)', $t['reversed_ugx']]);
            $row(['# Payments to WorkMate verified (UGX)', $t['settled_ugx']]);
        } else {
            $t = $this->finance->paymentTotals($providerId, $p);
            $row(['# Confirmed by customers in the period (UGX)', $t['confirmed']['amount_ugx'], $t['confirmed']['count'].' payments']);
            $row(['# Still waiting for the customer today (UGX, not counted as paid)', $t['awaiting']['amount_ugx'], $t['awaiting']['count'].' payments']);
            $row(['# Questioned by the customer today (UGX, not counted as paid)', $t['questioned']['amount_ugx'], $t['questioned']['count'].' payments']);
            $row(['Confirmed on (Kampala)', 'Paid on', 'Job', 'Job title', 'Method', 'Reference', 'Amount (UGX)']);
            foreach ($this->finance->paymentLines($providerId, $p) as $l) {
                $row([$this->when($l['confirmed_at']), $l['paid_on'], $l['job'], $l['title'], $l['method'], $l['reference'], $l['amount_ugx']]);
            }
        }
        fclose($out);
    }

    private function pdf(int $providerId, string $kind, array $p, string $business): string
    {
        $pdf = new SimplePdf('WorkMate statement');
        $pdf->heading($kind === 'commission' ? 'WorkMate commission statement' : 'Customer payments statement', 17);
        $pdf->line($business, 12, true);
        $pdf->line('Period: '.$p['label'], 10);
        $pdf->line('Generated '.now(Scheduling::TIMEZONE)->format('j M Y H:i').' (Kampala time). All amounts are whole Ugandan shillings (UGX).', 9);
        $pdf->gap(6);
        if ($kind === 'commission') {
            $pdf->line('Commission is what the business owes WorkMate on payments customers confirmed. It is not the customers\' payments to the business.', 9);
            $pdf->gap(4);
            $t = $this->finance->statementTotals($providerId, $p);
            $pdf->pair('Opening balance', $this->ugx($t['opening_ugx']), true);
            $pdf->pair('Commission assessed', $this->ugx($t['assessed_ugx']));
            $pdf->pair('Taken off (reversed)', '- '.$this->ugx($t['reversed_ugx']));
            $pdf->pair('Payments to WorkMate verified', '- '.$this->ugx($t['settled_ugx']));
            $pdf->pair('Closing balance (still owed)', $this->ugx($t['closing_ugx']), true);
            $pdf->gap(8);
            $rows = (function () use ($providerId, $p) {
                foreach ($this->finance->statementLines($providerId, $p, 3000) as $l) {
                    yield [
                        substr($this->when($l['at']), 0, 16), $this->typeWords($l['type']), ($l['job'] ? 'Job #'.$l['job'] : '').($l['cycle_id'] && ! $l['job'] ? 'Settlement #'.$l['cycle_id'] : ''),
                        (string) ($l['payment_reference'] ?: ($l['rate'] ? $l['rate'] : '')), ($l['amount_ugx'] < 0 ? '-' : '').number_format(abs($l['amount_ugx'])), number_format($l['balance_ugx']),
                    ];
                }
            })();
            $pdf->table([
                ['label' => 'Date', 'w' => 78, 'align' => 'l'], ['label' => 'What', 'w' => 150, 'align' => 'l'], ['label' => 'Job / settlement', 'w' => 85, 'align' => 'l'],
                ['label' => 'Rate / reference', 'w' => 80, 'align' => 'l'], ['label' => 'Amount (UGX)', 'w' => 65, 'align' => 'r'], ['label' => 'Balance (UGX)', 'w' => 57, 'align' => 'r'],
            ], $rows);
            if ($t['matches_ledger'] !== null) {
                $pdf->line($t['matches_ledger'] ? 'The closing balance matches the commission ledger.' : 'Check: the closing balance does not match the ledger. Contact WorkMate.', 9);
            }
        } else {
            $t = $this->finance->paymentTotals($providerId, $p);
            $pdf->line('Only payments the customer confirmed are counted as paid. WorkMate does not hold these payments.', 9);
            $pdf->gap(4);
            $pdf->pair('Confirmed by customers in the period', $this->ugx($t['confirmed']['amount_ugx']).' ('.$t['confirmed']['count'].')', true);
            $pdf->pair('Waiting for the customer today (not counted as paid)', $this->ugx($t['awaiting']['amount_ugx']).' ('.$t['awaiting']['count'].')');
            $pdf->pair('Questioned by the customer today (not counted as paid)', $this->ugx($t['questioned']['amount_ugx']).' ('.$t['questioned']['count'].')');
            $pdf->gap(8);
            $rows = (function () use ($providerId, $p) {
                foreach ($this->finance->paymentLines($providerId, $p, 3000) as $l) {
                    yield [substr($this->when($l['confirmed_at']), 0, 16), 'Job #'.$l['job'], (string) $l['title'], (string) $l['method'], (string) $l['reference'], number_format($l['amount_ugx'])];
                }
            })();
            $pdf->table([
                ['label' => 'Confirmed', 'w' => 78, 'align' => 'l'], ['label' => 'Job', 'w' => 55, 'align' => 'l'], ['label' => 'Title', 'w' => 150, 'align' => 'l'],
                ['label' => 'Method', 'w' => 70, 'align' => 'l'], ['label' => 'Reference', 'w' => 90, 'align' => 'l'], ['label' => 'Amount (UGX)', 'w' => 72, 'align' => 'r'],
            ], $rows);
        }

        return $pdf->output();
    }
}
