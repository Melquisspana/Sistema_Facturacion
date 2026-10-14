<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SinInternetController extends Controller
{
    public function __invoke(): Response
    {
        $url = config('dte.contingencia.url_local', '');
        $urlLocal = is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            ? $url : null;

        return response()->view('sin-internet', compact('urlLocal'))
            ->header('Cache-Control', 'no-store, private');
    }
}
