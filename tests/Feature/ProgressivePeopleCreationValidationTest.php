<?php

use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Driver\Driver\CreateDriverRequest;
use App\Http\Requests\Staff\CreateStaffRequest;
use Illuminate\Support\Facades\Validator;

it('requires only a phone to start customer staff and driver records', function (): void {
    $requests = [CreateCustomerRequest::class, CreateStaffRequest::class, CreateDriverRequest::class];

    foreach ($requests as $requestClass) {
        $request = new $requestClass;
        expect(Validator::make([], $request->rules())->errors()->has('phone'))->toBeTrue()
            ->and(Validator::make(['phone' => '+94771234567'], $request->rules())->errors()->has('phone'))->toBeFalse();
    }
});
