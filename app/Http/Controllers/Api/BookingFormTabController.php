<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookingFormTabController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:booking-form-tabs.view')->only(['index', 'show']);
        $this->middleware('permission:booking-form-tabs.create')->only(['store']);
        $this->middleware('permission:booking-form-tabs.edit')->only(['update']);
        $this->middleware('permission:booking-form-tabs.delete')->only(['destroy']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
