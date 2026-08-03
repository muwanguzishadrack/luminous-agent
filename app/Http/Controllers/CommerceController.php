<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class CommerceController extends Controller
{
    /**
     * Display the commerce workspace (products and orders).
     */
    public function __invoke(): Response
    {
        return Inertia::render('commerce/index', [
            'productCount' => Product::count(),
            'orderCount' => Order::count(),
        ]);
    }
}
