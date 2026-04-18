<?php

namespace App\Http\Controllers;

class ProductController extends Controller
{
    public function __invoke()
    {
        return view('products');
    }
}
