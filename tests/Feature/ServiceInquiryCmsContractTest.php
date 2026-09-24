<?php

use App\Http\Controllers\Api\InquiryFormController;
use App\Http\Controllers\Api\InquiryController as AdminInquiryController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\Api\Website\CmsContentController;
use App\Http\Requests\Website\CmsContent\CreateCmsContentRequest;
use App\Http\Requests\Website\CmsContent\UpdateCmsContentRequest;
use App\Http\Requests\Inquiry\UpdateInquiryRequest;
use App\Http\Resources\Inquiry\InquiryFormResource;
use App\Http\Resources\Inquiry\InquiryResource;
use App\Http\Resources\Website\CmsContentResource;
use App\Models\Inquiry;
use App\Models\InquiryForm;
use App\Models\Website\CmsContent;
use App\Services\Website\PublishedCmsContentResolver;
use App\Services\MailDispatchService;
use App\Services\Sms\SmsAutomationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

beforeEach(function () {
    Schema::dropIfExists('inquiry_form_fields');
    Schema::dropIfExists('inquiries');
    Schema::dropIfExists('cms_contents');
    Schema::dropIfExists('cms_content_types');
    Schema::dropIfExists('inquiry_forms');
    Schema::dropIfExists('hr_employment_spells');
    Schema::dropIfExists('staff');
    Schema::dropIfExists('companies');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('companies', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->boolean('is_active')->default(true);
        $table->boolean('is_default')->default(false);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('staff', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->uuid('company_id');
        $table->string('code');
        $table->timestamp('employment_ended_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('hr_employment_spells', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('staff_id');
        $table->uuid('company_id');
        $table->string('status');
        $table->timestamp('terminated_at')->nullable();
    });

    Schema::create('inquiry_forms', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('slug')->unique();
        $table->json('settings')->nullable();
        $table->text('success_message')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('cms_content_types', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('title');
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('cms_contents', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('cms_content_type_id');
        $table->uuid('inquiry_form_id')->nullable();
        $table->string('title');
        $table->string('slug')->unique();
        $table->string('status')->default('draft');
        $table->string('availability_status')->nullable();
        $table->integer('min_days')->nullable();
        $table->timestamp('published_at')->nullable();
        $table->json('custom_fields')->nullable();
        $table->boolean('is_active')->default(true);
        $table->uuid('created_user_id')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('inquiries', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('inquiry_number')->nullable()->unique();
        $table->uuid('cms_content_id')->nullable();
        $table->string('inquiry_type');
        $table->uuid('inquiry_service_page_id')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('phone')->nullable();
        $table->string('subject')->nullable();
        $table->string('status')->nullable();
        $table->uuid('assigned_to')->nullable();
        $table->uuid('updated_user_id')->nullable();
        $table->text('response')->nullable();
        $table->timestamp('responded_at')->nullable();
        $table->text('notes')->nullable();
        $table->string('source')->nullable();
        $table->string('service_type')->nullable();
        $table->json('payload')->nullable();
        $table->text('message');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
});

it('persists a CMS inquiry with exact source and form metadata and invokes existing notifications', function () {
    Schema::create('inquiry_form_fields', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_form_id');
        $table->string('name');
        $table->string('label');
        $table->string('type');
        $table->boolean('is_required')->default(false);
        $table->string('validation_rules')->nullable();
        $table->json('conditional_logic')->nullable();
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    $typeId = (string) Str::uuid();
    DB::table('cms_content_types')->insert([
        'id' => $typeId, 'title' => 'Services', 'slug' => 'services',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create([
        'name' => 'City form', 'slug' => 'city-form', 'is_active' => true,
        'settings' => ['submission_workflow' => 'corporate'],
        'success_message' => 'We received your city inquiry.',
    ]));
    foreach (['name' => 'text', 'email' => 'email', 'phone' => 'tel'] as $name => $type) {
        DB::table('inquiry_form_fields')->insert([
            'id' => (string) Str::uuid(), 'inquiry_form_id' => $form->id,
            'name' => $name, 'label' => ucfirst($name), 'type' => $type,
            'is_required' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('inquiry_form_fields')->insert([
        'id' => (string) Str::uuid(), 'inquiry_form_id' => $form->id,
        'name' => 'brief', 'label' => 'Brief', 'type' => 'file',
        'is_required' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $service = CmsContent::withoutEvents(fn () => CmsContent::create([
        'cms_content_type_id' => $typeId, 'inquiry_form_id' => $form->id,
        'title' => 'City ride', 'slug' => 'city-ride', 'status' => 'published',
        'published_at' => now()->subMinute(), 'is_active' => true,
    ]));
    activity()->disableLogging();
    $mail = Mockery::mock(MailDispatchService::class);
    $mail->shouldReceive('sendToCustomer')->once()->withArgs(fn ($address) => $address === 'alice@example.com');
    app()->instance(MailDispatchService::class, $mail);
    $sms = Mockery::mock(SmsAutomationService::class);
    $sms->shouldReceive('queueWebsiteInquiryReceived')->once();
    app()->instance(SmsAutomationService::class, $sms);

    $request = Request::create('/booking/enquiry', 'POST', [
        'cms_content_id' => $service->id,
        'cms_form_context' => Crypt::encryptString(json_encode(['content_id' => $service->id, 'form_id' => $form->id])),
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
        'utm_source' => 'newsletter',
        'referrer' => 'https://example.org/campaign',
        'name' => 'Alice Smith', 'email' => 'alice@example.com', 'phone' => '+94771234567',
    ]);
    $request->headers->set('referer', 'http://localhost/services/city-ride');
    Storage::fake('local');
    $request->files->set('brief', UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf'));
    app()->instance('request', $request);
    $response = app(InquiryController::class)->store($request);

    $inquiry = Inquiry::firstOrFail();
    $notificationResults = $inquiry->fresh()->payload['meta']['notification_results'];
    expect($response->getTargetUrl())->toContain('#service-inquiry')
        ->and(session('success'))->toBe('We received your city inquiry.')
        ->and(session('inquiry_reference'))->toBe($inquiry->inquiry_number)
        ->and($inquiry->cms_content_id)->toBe($service->id)
        ->and($inquiry->inquiry_type)->toBe('corporate')
        ->and($inquiry->inquiry_service_page_id)->toBeNull()
        ->and($inquiry->payload['form_id'])->toBe($form->id)
        ->and($inquiry->payload['service_slug'])->toBe('city-ride')
        ->and($inquiry->payload['meta']['source_url'])->toBe('http://localhost/services/city-ride')
        ->and($inquiry->payload['meta']['referrer'])->toBe('https://example.org/campaign')
        ->and($inquiry->payload['meta']['utm']['utm_source'])->toBe('newsletter')
        ->and($inquiry->payload['form_updated_at'])->not->toBeNull()
        ->and($inquiry->payload['form']['name'])->toBe('Alice Smith')
        ->and($inquiry->payload['attachments']['brief']['original_name'])->toBe('brief.pdf')
        ->and(Storage::disk('local')->exists($inquiry->payload['attachments']['brief']['path']))->toBeTrue()
        ->and($inquiry->inquiry_number)->toStartWith('INQ');
    expect($notificationResults)->toBe(['sms' => 'queued', 'email' => 'sent']);

    $invalid = Request::create('/booking/enquiry', 'POST', [
        'cms_content_id' => $service->id,
        'cms_form_context' => Crypt::encryptString(json_encode(['content_id' => $service->id, 'form_id' => $form->id])),
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
        'name' => 'Alice Smith', 'email' => 'not-an-email', 'phone' => '+94771234567',
    ]);
    $invalid->headers->set('referer', 'http://localhost/services/city-ride');
    app()->instance('request', $invalid);
    expect(fn () => app(InquiryController::class)->store($invalid))->toThrow(ValidationException::class);

    $tampered = Request::create('/booking/enquiry', 'POST', [
        'cms_content_id' => (string) Str::uuid(),
        'cms_form_context' => Crypt::encryptString(json_encode(['content_id' => $service->id, 'form_id' => $form->id])),
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
        'name' => 'Bob Smith', 'email' => 'bob@example.com', 'phone' => '+94771234567',
    ]);
    $tampered->headers->set('referer', 'http://localhost/services/city-ride');
    app()->instance('request', $tampered);
    app(InquiryController::class)->store($tampered);

    $spam = Request::create('/booking/enquiry', 'POST', [
        'cms_content_id' => $service->id,
        'cms_form_context' => Crypt::encryptString(json_encode(['content_id' => $service->id, 'form_id' => $form->id])),
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
        '_inquiry_website' => 'bot.example',
        'name' => 'Bot Person', 'email' => 'bot@example.com', 'phone' => '+94771234567',
    ]);
    app()->instance('request', $spam);
    app(InquiryController::class)->store($spam);

    expect(Inquiry::count())->toBe(1);

    $admin = app(AdminInquiryController::class);
    $filters = Request::create('/api/inquiries', 'GET', [
        'service_id' => $service->id, 'form_id' => $form->id,
        'workflow' => 'corporate', 'status' => 'open',
        'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
    ]);
    expect($admin->index($filters)->collection)->toHaveCount(1)
        ->and($admin->index(Request::create('/api/inquiries', 'GET', ['form_id' => (string) Str::uuid()]))->collection)->toHaveCount(0);
    $resource = (new InquiryResource($inquiry->fresh()->load('cmsContent')))->resolve($filters);
    expect($resource['service_title'])->toBe('City ride')
        ->and($resource['form_name'])->toBe('City form')
        ->and($resource['service_url'])->toContain('/services/city-ride')
        ->and($resource['notification_results'])->toBe(['sms' => 'queued', 'email' => 'sent']);

    $assigneeId = (string) Str::uuid();
    DB::table('users')->insert([
        'id' => $assigneeId, 'first_name' => 'Agent', 'last_name' => 'One',
        'email' => 'agent@example.com', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $companyId = (string) Str::uuid();
    $staffId = (string) Str::uuid();
    DB::table('companies')->insert(['id' => $companyId, 'name' => 'Default company', 'is_active' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('staff')->insert(['id' => $staffId, 'user_id' => $assigneeId, 'company_id' => $companyId, 'code' => 'ST-1', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hr_employment_spells')->insert(['id' => (string) Str::uuid(), 'staff_id' => $staffId, 'company_id' => $companyId, 'status' => 'active']);
    $operator = (object) ['id' => $assigneeId];
    $assignRequest = Request::create('/api/inquiries/'.$inquiry->id.'/assign', 'PUT', ['assigned_to' => $assigneeId]);
    $assignRequest->setUserResolver(fn () => $operator);
    $optionsRequest = Request::create('/api/inquiries/assignee-options', 'GET', ['selected_id' => $assigneeId]);
    $optionsRequest->setUserResolver(fn () => $operator);
    expect($admin->assigneeOptions($optionsRequest)->getData(true)['data']['data'][0]['label'])->toBe('Agent One · ST-1');
    $admin->assign($assignRequest, $inquiry);
    $statusRequest = Request::create('/api/inquiries/'.$inquiry->id.'/status', 'PUT', ['status' => 'in_progress']);
    $statusRequest->setUserResolver(fn () => $operator);
    $admin->updateStatus($statusRequest, $inquiry->fresh());
    $responseRequest = Request::create('/api/inquiries/'.$inquiry->id.'/respond', 'POST', [
        'message' => 'We will follow up shortly.', 'status' => 'closed',
    ]);
    $responseRequest->setUserResolver(fn () => $operator);
    $admin->respond($responseRequest, $inquiry->fresh());
    $notesRequest = UpdateInquiryRequest::create('/api/inquiries/'.$inquiry->id, 'PUT', [
        'notes' => 'Follow up with the customer tomorrow.',
    ]);
    $notesRequest->setContainer(app());
    $notesRequest->setRedirector(app('redirect'));
    $notesRequest->setUserResolver(fn () => $operator);
    $notesRequest->setValidator(Validator::make($notesRequest->all(), $notesRequest->rules()));
    $admin->update($notesRequest, $inquiry->fresh());
    expect($inquiry->fresh()->assigned_to)->toBe($assigneeId)
        ->and($inquiry->fresh()->status)->toBe('closed')
        ->and($inquiry->fresh()->response)->toBe('We will follow up shortly.')
        ->and($inquiry->fresh()->responded_at)->not->toBeNull()
        ->and($inquiry->fresh()->notes)->toBe('Follow up with the customer tomorrow.');

    $failedMail = Mockery::mock(MailDispatchService::class);
    $failedMail->shouldReceive('sendToCustomer')->once()->andThrow(new RuntimeException('Mail unavailable'));
    app()->instance(MailDispatchService::class, $failedMail);
    $failedSms = Mockery::mock(SmsAutomationService::class);
    $failedSms->shouldReceive('queueWebsiteInquiryReceived')->once()->andThrow(new RuntimeException('SMS unavailable'));
    app()->instance(SmsAutomationService::class, $failedSms);
    $second = Request::create('/booking/enquiry', 'POST', [
        'cms_content_id' => $service->id,
        'cms_form_context' => Crypt::encryptString(json_encode(['content_id' => $service->id, 'form_id' => $form->id])),
        '_inquiry_form_token' => Crypt::encryptString((string) (now()->timestamp - 10)),
        'name' => 'Carol Brown', 'email' => 'carol@example.com', 'phone' => '+94779876543',
    ]);
    $second->headers->set('referer', 'http://localhost/services/city-ride');
    app()->instance('request', $second);
    $failedResponse = app(InquiryController::class)->store($second);
    $failedInquiry = Inquiry::where('email', 'carol@example.com')->firstOrFail();
    expect($failedResponse->getTargetUrl())->toContain('#service-inquiry')
        ->and($failedInquiry->payload['meta']['notification_results'])->toBe(['sms' => 'failed', 'email' => 'failed'])
        ->and(Inquiry::count())->toBe(2);
});

it('resolves one published CMS service with its attached form and preserves draft invisibility', function () {
    Schema::create('inquiry_form_fields', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_form_id');
        $table->string('name');
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    $typeId = (string) Str::uuid();
    DB::table('cms_content_types')->insert([
        'id' => $typeId, 'title' => 'Services', 'slug' => 'services',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create([
        'name' => 'Service form', 'slug' => 'service-form', 'is_active' => true,
    ]));
    $service = CmsContent::withoutEvents(fn () => CmsContent::create([
        'cms_content_type_id' => $typeId, 'inquiry_form_id' => $form->id,
        'title' => 'City ride', 'slug' => 'city-ride', 'status' => 'published',
        'published_at' => now()->subMinute(), 'is_active' => true,
    ]));

    $resolver = app(PublishedCmsContentResolver::class);
    $resolved = $resolver->find('services', 'city-ride');
    expect($resolved?->id)->toBe($service->id)
        ->and($resolved?->relationLoaded('inquiryForm'))->toBeTrue()
        ->and($resolved?->inquiryForm?->id)->toBe($form->id)
        ->and($resolved?->inquiryForm?->relationLoaded('fields'))->toBeTrue();

    CmsContent::withoutEvents(fn () => $service->update(['status' => 'draft']));
    expect($resolver->find('services', 'city-ride'))->toBeNull();
});

it('accepts only a signed pairing for a published CMS service with an active form', function () {
    Schema::create('inquiry_form_fields', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_form_id');
        $table->string('name');
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    $typeId = (string) Str::uuid();
    DB::table('cms_content_types')->insert([
        'id' => $typeId, 'title' => 'Services', 'slug' => 'services',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create([
        'name' => 'City form', 'slug' => 'city-form', 'is_active' => true,
    ]));
    $service = CmsContent::withoutEvents(fn () => CmsContent::create([
        'cms_content_type_id' => $typeId, 'inquiry_form_id' => $form->id,
        'title' => 'City ride', 'slug' => 'city-ride', 'status' => 'published',
        'published_at' => now()->subMinute(), 'is_active' => true,
    ]));
    $context = Crypt::encryptString(json_encode(['content_id' => $service->id, 'form_id' => $form->id]));
    $resolve = fn (array $payload) => (function () use ($payload) {
        return $this->resolveCmsInquiryService(Request::create('/booking/enquiry', 'POST', $payload));
    })->call(app(InquiryController::class));

    expect($resolve(['cms_content_id' => $service->id, 'cms_form_context' => $context])?->id)->toBe($service->id)
        ->and($resolve(['cms_content_id' => (string) Str::uuid(), 'cms_form_context' => $context]))->toBeNull()
        ->and($resolve(['cms_content_id' => $service->id, 'cms_form_context' => 'tampered']))->toBeNull();

    CmsContent::withoutEvents(fn () => $service->update(['status' => 'draft']));
    expect($resolve(['cms_content_id' => $service->id, 'cms_form_context' => $context]))->toBeNull();

    CmsContent::withoutEvents(fn () => $service->update(['status' => 'published']));
    InquiryForm::withoutEvents(fn () => $form->update(['is_active' => false]));
    expect($resolve(['cms_content_id' => $service->id, 'cms_form_context' => $context]))->toBeNull();
});

it('enforces safe file validation for reusable inquiry form attachments', function () {
    Schema::create('inquiry_form_fields', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('inquiry_form_id');
        $table->string('name');
        $table->string('label');
        $table->string('type');
        $table->boolean('is_required')->default(false);
        $table->string('validation_rules')->nullable();
        $table->json('conditional_logic')->nullable();
        $table->json('options')->nullable();
        $table->integer('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create([
        'name' => 'Attachment form', 'slug' => 'attachment-form', 'is_active' => true,
    ]));
    DB::table('inquiry_form_fields')->insert([
        'id' => (string) Str::uuid(), 'inquiry_form_id' => $form->id,
        'name' => 'attachment', 'label' => 'Attachment', 'type' => 'file',
        'is_required' => true, 'validation_rules' => 'required',
        'conditional_logic' => json_encode(['field' => 'needs_attachment', 'operator' => 'equals', 'value' => 'yes']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('inquiry_form_fields')->insert([
        'id' => (string) Str::uuid(), 'inquiry_form_id' => $form->id,
        'name' => 'interests', 'label' => 'Interests', 'type' => 'checkbox',
        'is_required' => false, 'options' => json_encode([['value' => 'city', 'label' => 'City']]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($form->load('fields')->buildValidationRules(['needs_attachment' => 'yes'])['attachment'])->toContain(
        'required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'
    )->and($form->buildValidationRules(['needs_attachment' => 'no'])['attachment'])->toBe(['exclude'])
        ->and($form->buildValidationRules()['interests'])->toContain('array')
        ->and($form->buildValidationRules()['interests.*'])->toBe(['in:city']);
});

function serviceInquiryValidator(array $payload)
{
    $request = CreateCmsContentRequest::create('/api/cms-contents', 'POST', $payload);
    $request->setContainer(app());
    $validator = Validator::make($payload, $request->rules());
    $validator->after($request->after());

    return $validator;
}

it('scopes inquiry configuration to services and requires an active form for an enabled published CTA', function () {
    $serviceTypeId = (string) Str::uuid();
    $pageTypeId = (string) Str::uuid();
    $activeFormId = (string) Str::uuid();
    $inactiveFormId = (string) Str::uuid();

    DB::table('cms_content_types')->insert([
        ['id' => $serviceTypeId, 'title' => 'Services', 'slug' => 'services', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $pageTypeId, 'title' => 'Pages', 'slug' => 'pages', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('inquiry_forms')->insert([
        ['id' => $activeFormId, 'name' => 'Active', 'slug' => 'active', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $inactiveFormId, 'name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $base = ['title' => 'Test', 'slug' => 'test', 'status' => 'published'];
    $cta = ['custom_fields' => ['inquiry_cta' => ['enabled' => true, 'label' => 'Enquire now']]];

    expect(serviceInquiryValidator($base + $cta + [
        'cms_content_type_id' => $pageTypeId,
        'inquiry_form_id' => $activeFormId,
    ])->fails())->toBeTrue()
        ->and(serviceInquiryValidator($base + $cta + [
            'cms_content_type_id' => $serviceTypeId,
            'inquiry_form_id' => $inactiveFormId,
        ])->errors()->has('inquiry_form_id'))->toBeTrue()
        ->and(serviceInquiryValidator($base + $cta + [
            'cms_content_type_id' => $serviceTypeId,
            'inquiry_form_id' => $activeFormId,
        ])->passes())->toBeTrue();
});

it('exposes compact form context, source relationships, and blocks deletion while attached', function () {
    $typeId = (string) Str::uuid();
    DB::table('cms_content_types')->insert([
        'id' => $typeId, 'title' => 'Services', 'slug' => 'services', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create(['name' => 'Airport form', 'slug' => 'airport-form', 'is_active' => true]));
    $service = CmsContent::withoutEvents(fn () => CmsContent::create([
        'cms_content_type_id' => $typeId,
        'inquiry_form_id' => $form->id,
        'title' => 'Airport transfers',
        'slug' => 'airport-transfers',
        'status' => 'draft',
    ]));
    $inquiry = Inquiry::withoutEvents(fn () => Inquiry::create([
        'cms_content_id' => $service->id,
        'inquiry_type' => 'general',
        'message' => 'Test',
    ]));

    $resource = (new CmsContentResource($service->load('inquiryForm')))->resolve();
    $response = app(InquiryFormController::class)->destroy($form);
    $formResource = (new InquiryFormResource($form->load('cmsServices')))->resolve();

    expect($resource['inquiry_form'])->toMatchArray([
        'id' => $form->id,
        'name' => 'Airport form',
        'is_active' => true,
    ])->and($service->inquiries()->first()->is($inquiry))->toBeTrue()
        ->and($inquiry->cmsContent->is($service))->toBeTrue()
        ->and($response->getStatusCode())->toBe(422)
        ->and($formResource['services']->first()['id'])->toBe($service->id)
        ->and(InquiryForm::withInactive()->find($form->id))->not->toBeNull();
});

it('keeps CMS, form, and inquiry mutation permissions separate', function () {
    $contracts = [
        ['POST', 'api/cms-contents', 'permission:cms-contents.create'],
        ['PUT', 'api/cms-contents/{cms_content}', 'permission:cms-contents.edit'],
        ['POST', 'api/inquiry-forms', 'permission:inquiry-forms.create'],
        ['PUT', 'api/inquiry-forms/{inquiry_form}', 'permission:inquiry-forms.edit'],
        ['DELETE', 'api/inquiry-forms/{inquiry_form}', 'permission:inquiry-forms.delete'],
        ['GET', 'api/inquiries/filter-options', 'permission:inquiries.view'],
        ['PUT', 'api/inquiries/{inquiry}/status', 'permission:inquiries.edit'],
    ];

    foreach ($contracts as [$method, $uri, $permission]) {
        $route = collect(Route::getRoutes())->first(fn ($route) => in_array($method, $route->methods(), true)
            && $route->uri() === $uri);
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain($permission);
        if (str_contains($uri, 'api/inquiries')) {
            expect($route->gatherMiddleware())->not->toContain('permission:system.view');
        }
    }
});

it('persists and reloads service inquiry settings and clears public caches on publish changes', function () {
    $typeId = (string) Str::uuid();
    DB::table('cms_content_types')->insert([
        'id' => $typeId, 'title' => 'Services', 'slug' => 'services', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create([
        'name' => 'Reload form', 'slug' => 'reload-form', 'is_active' => true,
    ]));
    $service = CmsContent::withoutEvents(fn () => CmsContent::create([
        'cms_content_type_id' => $typeId,
        'inquiry_form_id' => $form->id,
        'title' => 'Reload service',
        'slug' => 'reload-service',
        'status' => 'draft',
        'custom_fields' => ['inquiry_cta' => ['enabled' => true, 'label' => 'Ask us']],
    ]));

    $reloaded = CmsContent::withInactive()->findOrFail($service->id);
    expect($reloaded->inquiry_form_id)->toBe($form->id)
        ->and(data_get($reloaded->custom_fields, 'inquiry_cta.label'))->toBe('Ask us');

    Cache::put('header_services', true);
    Cache::put('sitemap', true);
    Cache::put('service_pages', true);
    $userId = (string) Str::uuid();
    DB::table('users')->insert(['id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    $request = Request::create('/api/cms-contents/'.$service->id.'/publish', 'PUT');
    $request->setUserResolver(fn () => (object) ['id' => $userId]);
    $controller = app(CmsContentController::class);

    CmsContent::withoutEvents(fn () => $controller->publish($request, $reloaded));
    expect($reloaded->fresh()->status)->toBe('published')
        ->and(Cache::has('header_services'))->toBeFalse()
        ->and(Cache::has('sitemap'))->toBeFalse()
        ->and(Cache::has('service_pages'))->toBeFalse();

    CmsContent::withoutEvents(fn () => $controller->unpublish($request, $reloaded->fresh()));
    expect($reloaded->fresh()->status)->toBe('draft');
});

it('creates a CMS service through the validated controller and returns the attached form after reload', function () {
    $typeId = (string) Str::uuid();
    $userId = (string) Str::uuid();
    DB::table('users')->insert(['id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('cms_content_types')->insert([
        'id' => $typeId, 'title' => 'Services', 'slug' => 'services', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $form = InquiryForm::withoutEvents(fn () => InquiryForm::create([
        'name' => 'Controller form', 'slug' => 'controller-form', 'is_active' => true,
    ]));
    $request = CreateCmsContentRequest::create('/api/cms-contents', 'POST', [
        'cms_content_type_id' => $typeId,
        'title' => 'Controller service',
        'slug' => 'controller-service',
        'status' => 'draft',
        'inquiry_form_id' => $form->id,
        'custom_fields' => ['inquiry_cta' => ['enabled' => true, 'label' => 'Ask now']],
    ]);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(fn () => (object) ['id' => $userId]);
    $request->validateResolved();

    $response = CmsContent::withoutEvents(fn () => app(CmsContentController::class)->store($request));
    $contentId = json_decode($response->getContent(), true)['data']['content']['id'];
    $reloaded = CmsContent::withInactive()->with('inquiryForm')->findOrFail($contentId);

    expect($response->getStatusCode())->toBe(201)
        ->and($reloaded->inquiry_form_id)->toBe($form->id)
        ->and(data_get($reloaded->custom_fields, 'inquiry_cta.label'))->toBe('Ask now')
        ->and($reloaded->inquiryForm->name)->toBe('Controller form');

    $update = UpdateCmsContentRequest::create('/api/cms-contents/'.$contentId, 'PUT', [
        'custom_fields' => ['inquiry_cta' => ['enabled' => true, 'label' => 'Request a quote']],
        'status' => 'published',
    ]);
    $route = new Illuminate\Routing\Route('PUT', 'api/cms-contents/{cms_content}', []);
    $route->bind($update);
    $route->setParameter('cms_content', $reloaded);
    $update->setRouteResolver(fn () => $route);
    $update->setContainer(app());
    $update->setRedirector(app('redirect'));
    $update->setUserResolver(fn () => (object) ['id' => $userId]);
    $update->validateResolved();

    $updated = CmsContent::withoutEvents(fn () => app(CmsContentController::class)->update($update, $reloaded));
    $reloaded = CmsContent::withInactive()->findOrFail($contentId);
    expect($updated->getStatusCode())->toBe(200)
        ->and($reloaded->status)->toBe('published')
        ->and(data_get($reloaded->custom_fields, 'inquiry_cta.label'))->toBe('Request a quote');
});
