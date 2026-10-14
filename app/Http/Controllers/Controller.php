<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    protected function paginationSize(Request $request, int $default = 25): int
    {
        return min(100, max(1, $request->integer('per_page', $default)));
    }
}
