<?php

namespace App\Http\Controllers;

use App\Support\Branding;

/**
 * AGPL「获取源代码」入口：始终可访问，没有关闭开关。
 */
final class SourceCodeController extends Controller
{
    public function __invoke()
    {
        return view('source.index', ['brand' => Branding::all()]);
    }
}
