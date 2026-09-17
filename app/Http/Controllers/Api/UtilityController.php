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
use Illuminate\Support\Facades\Cache;

class UtilityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function countries()
    {
        $countries = Cache::remember('ref.countries', 86400, fn () =>
            Country::select('id', 'name', 'code')->get()
        );
        return response()
            ->json(CountryResource::collection($countries))
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Display a listing of the resource.
     */
    public function states(Request $request, ?string $country = null)
    {
        $countryId = $country ?? (string) $request->validate([
            'country' => ['required', 'uuid', 'exists:countries,id'],
        ])['country'];
        $states = Cache::remember("ref.states.{$countryId}", 86400, fn () =>
            State::where('country_id', $countryId)->orderBy('name')->get()
        );
        return response()
            ->json(StateResource::collection($states))
            ->header('Cache-Control', 'public, max-age=86400');
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
