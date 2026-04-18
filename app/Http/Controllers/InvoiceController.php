<?php

namespace App\Http\Controllers;

class InvoiceController extends Controller
{
    public function __invoke()
    {
        return view('invoices');
    }
}
