<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class AsyncHttpRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $url;

    /**
     * Create a new job instance.
     */
    public function __construct($url)
    {
        $this->url = $url;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $parsedUrl = $this->url;

        // Rebuild the URL manually
        $combinedUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $combinedUrl .= ':' . $parsedUrl['port'];
        }
        $combinedUrl .= $parsedUrl['path'];
        if (isset($parsedUrl['query'])) {
            $combinedUrl .= '?' . $parsedUrl['query'];
        }
        if (isset($parsedUrl['fragment'])) {
            $combinedUrl .= '#' . $parsedUrl['fragment'];
        }
        // Perform the HTTP request using Laravel HTTP client
        $response = Http::timeout(0)->get($combinedUrl);
    }
}
