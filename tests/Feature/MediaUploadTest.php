<?php

use App\Http\Controllers\Api\FileUploadController;
use App\Models\User;
use App\Models\UserMedia;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['filesystems.default' => 'public', 'activitylog.enabled' => false]);
    Storage::fake('public');
    (require database_path('migrations/2025_07_29_070902_create_user_media_table.php'))->up();
    $this->actor = (new User)->forceFill(['id' => '00000000-0000-4000-8000-000000000001']);
    $this->controller = app(FileUploadController::class);
});

function mediaUploadRequest(UploadedFile $file, array $data = []): Request
{
    $request = Request::create('/api/files/upload', 'POST', $data, [], ['file' => $file]);
    $request->setUserResolver(fn () => test()->actor);
    return $request;
}

it('compresses and resizes large images and records their actual stored size', function () {
    $request = mediaUploadRequest(UploadedFile::fake()->image('photo.png', 3000, 1500)->size(12000), ['category' => 'gallery']);
    $response = $this->controller->uploadFile($request);
    expect($response->getStatusCode())->toBe(201);
    $data = $response->getData(true)['data'];
    $media = UserMedia::findOrFail($data['id']);
    expect($data['mime_type'])->toBe('image/webp')
        ->and($data['path'])->toEndWith('.webp')
        ->and($data['width'])->toBe(2560)
        ->and($data['height'])->toBe(1280)
        ->and($data['size'])->toBe(Storage::disk('public')->size($data['path']))
        ->and($media->size)->toBe($data['size'])
        ->and($media->metadata['original_size'])->toBe(12000 * 1024);
    expect(getimagesizefromstring(Storage::disk('public')->get($data['path']))[0])->toBe(2560);
});

it('rejects wrong file types and oversized images with validation errors', function () {
    expect(fn () => $this->controller->uploadFile(mediaUploadRequest(
        UploadedFile::fake()->create('payload.txt', 1, 'text/plain'), ['category' => 'gallery']
    )))->toThrow(ValidationException::class);
    expect(fn () => $this->controller->uploadFile(mediaUploadRequest(
        UploadedFile::fake()->image('large.jpg')->size(51201), ['category' => 'gallery']
    )))->toThrow(ValidationException::class);
    expect(UserMedia::count())->toBe(0);
});

it('rejects traversal in custom storage paths', function () {
    $response = $this->controller->uploadFile(mediaUploadRequest(
        UploadedFile::fake()->image('photo.jpg'), ['path' => '../private']
    ));
    expect($response->getStatusCode())->toBe(422)->and(UserMedia::count())->toBe(0);
});

it('supports safe SVG logos and rejects executable SVG content', function () {
    $safe = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"/></svg>');
    $response = $this->controller->uploadFile(mediaUploadRequest($safe, ['category' => 'logos']));
    expect($response->getStatusCode())->toBe(201);
    $unsafe = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
    expect(fn () => $this->controller->uploadFile(mediaUploadRequest($unsafe, ['category' => 'logos'])))
        ->toThrow(ValidationException::class);
});

it('allows PDF vehicle documents through the document category', function () {
    $file = UploadedFile::fake()->create('insurance.pdf', 200, 'application/pdf');
    $response = $this->controller->uploadFile(mediaUploadRequest($file, ['category' => 'documents', 'path' => 'vehicles/insurance-documents']));
    expect($response->getStatusCode())->toBe(201)
        ->and($response->getData(true)['data']['mime_type'])->toBe('application/pdf');
});

it('lists only owned media with search, type filtering and pagination', function () {
    $first = $this->controller->uploadFile(mediaUploadRequest(UploadedFile::fake()->image('taxi.jpg')))->getData(true)['data'];
    UserMedia::findOrFail($first['id'])->update(['is_active' => false]);
    $other = UserMedia::withInactive()->findOrFail($first['id'])->replicate();
    $other->user_id = '00000000-0000-4000-8000-000000000002';
    $other->save();
    $request = Request::create('/api/files', 'GET', ['search' => 'taxi', 'type' => 'image', 'per_page' => 1]);
    $request->setUserResolver(fn () => $this->actor);
    $result = $this->controller->index($request)->getData(true);
    expect($result['meta']['total'])->toBe(2)
        ->and($result['data'][0]['id'])->toBe($first['id'])
        ->and($result['data'][0]['url'])->toBe(UserMedia::withInactive()->findOrFail($first['id'])->url);
});

it('keeps documents visible to their owner only while sharing image assets', function () {
    $own = $this->controller->uploadFile(mediaUploadRequest(UploadedFile::fake()->create('taxi.pdf', 1, 'application/pdf')))->getData(true)['data'];
    $other = UserMedia::withInactive()->findOrFail($own['id'])->replicate();
    $other->user_id = '00000000-0000-4000-8000-000000000002';
    $other->save();
    $request = Request::create('/api/files', 'GET', ['type' => 'document']);
    $request->setUserResolver(fn () => $this->actor);
    $result = $this->controller->index($request)->getData(true);
    expect($result['meta']['total'])->toBe(1)
        ->and($result['data'][0]['id'])->toBe($own['id']);
});

it('renames owned media without moving files and prevents other users from renaming it', function () {
    $data = $this->controller->uploadFile(mediaUploadRequest(UploadedFile::fake()->image('taxi.jpg')))->getData(true)['data'];
    $request = Request::create('/api/files/' . $data['id'], 'PATCH', ['original_name' => 'Taxi exterior']);
    $request->setUserResolver(fn () => $this->actor);
    $this->controller->updateFile($request, $data['id']);
    expect(UserMedia::find($data['id'])->original_name)->toBe('Taxi exterior');
    Storage::disk('public')->assertExists($data['path']);
    $request->setUserResolver(fn () => (new User)->forceFill(['id' => 'other-user']));
    expect(fn () => $this->controller->updateFile($request, $data['id']))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
