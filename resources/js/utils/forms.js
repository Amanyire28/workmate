/** Move focus to the first invalid field so keyboard and screen-reader users land on the problem. */
export function focusFirstError(root = document) {
    root.querySelector('[aria-invalid="true"]')?.focus();
}
