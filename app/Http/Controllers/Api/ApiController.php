<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function checkNpm()
    {
        return response()->json([
            'message' => shell_exec('where npm')
        ]);
    }
}
