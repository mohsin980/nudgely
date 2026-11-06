<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * A sample customer CSV to fill in.
 */
class CustomerImportController extends Controller
{
    public function sample(): Response
    {
        $csv = "First name,Last name,Email,Phone,Company,Notes\n"
            ."Jane,Smith,jane@example.com,(214) 555-0142,Smith Plumbing,Wants a quote for a new AC unit\n"
            ."Mike,Brown,mike@example.com,,,\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="quoteflow-customers-sample.csv"',
        ]);
    }
}
