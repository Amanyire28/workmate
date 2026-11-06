<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A photo of the job on a request, in a full and a thumbnail size. Private. */
class ServiceRequestPhoto extends Model
{
    public const DIRECTORY = 'request-photos';
    public const MAX = 6;

    protected $guarded = ['*'];

    protected $casts = [
        'service_request_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'position' => 'integer',
    ];

    // Storage paths stay on the server
    protected $hidden = ['path', 'thumb_path'];

    public function request()
    {
        return $this->belongsTo(ServiceRequest::class, 'service_request_id')->withTrashed();
    }

    public function url(bool $thumb = false): string
    {
        return url('/api/v1/requests/'.$this->service_request_id.'/photos/'.$this->uuid.($thumb ? '-t' : '').'.jpg');
    }

    /** What the API returns: links, never paths */
    public function present(): array
    {
        return ['id' => $this->uuid, 'url' => $this->url(), 'thumb_url' => $this->url(true), 'width' => $this->width, 'height' => $this->height];
    }
}
