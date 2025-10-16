<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CountryResource;
use App\Http\Resources\StateResource;
use App\Http\Resources\SystemConstantResource;
use App\Models\Country;
use App\Models\State;
use App\Models\SystemConstant;
use Illuminate\Http\Request;

class UtilityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function countries()
    {
        $countries = Country::select('id', 'name', 'code')->get();
        return CountryResource::collection($countries);
    }

    /**
     * Display a listing of the resource.
     */
    public function states(Request $request)
    {
        $states = State::where('country_id', $request->country)->get();
        return StateResource::collection($states);
    }

    public function constants(Request $request)
    {
        $systemConstant = SystemConstant::all();
        return SystemConstantResource::collection($systemConstant);
    }

    public function searchStates()
    {
        $states = State::select('id', 'country_id', 'name')->with([
            'country' => function ($query) {
                $query->select('id', 'name', 'code');
            }
        ])->get();
        return StateResource::collection($states);
    }
}
