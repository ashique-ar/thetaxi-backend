<?php
// app/Http/Controllers/Api/ImageGalleryController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImageGallery;
use App\Http\Requests\ImageGallery\CreateImageGalleryRequest;
use App\Http\Requests\ImageGallery\UpdateImageGalleryRequest;
use App\Http\Resources\ImageGallery\ImageGalleryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ImageGalleryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:image-galleries.view')->only(['index','show']);
        $this->middleware('permission:image-galleries.create')->only(['store']);
        $this->middleware('permission:image-galleries.edit')->only(['update']);
        $this->middleware('permission:image-galleries.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ImageGallery::query();
        if ($request->filled('search')) {
            $q->where('title','like','%'.$request->search.'%');
        }
        return ImageGalleryResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'total' => ImageGallery::count(),
                'active' => ImageGallery::where('is_active', true)->count(),
                'inactive' => ImageGallery::where('is_active', false)->count(),
            ],
        ]);
    }

    public function search(Request $request): AnonymousResourceCollection
    {
        $q = ImageGallery::query();
        if ($request->filled('q')) {
            $q->where('title', 'like', '%' . $request->q . '%');
        }

        return ImageGalleryResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [],
        ]);
    }

    public function store(CreateImageGalleryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $img = ImageGallery::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Image added to gallery',
            'data'=>['image'=>new ImageGalleryResource($img)]
        ],201);
    }

    public function show(ImageGallery $imageGallery): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['image'=>new ImageGalleryResource($imageGallery)]
        ]);
    }

    public function update(UpdateImageGalleryRequest $request, ImageGallery $imageGallery): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $imageGallery->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Image updated',
            'data'=>['image'=>new ImageGalleryResource($imageGallery)]
        ]);
    }

    public function destroy(ImageGallery $imageGallery): JsonResponse
    {
        $imageGallery->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Image removed from gallery'
        ]);
    }
}
