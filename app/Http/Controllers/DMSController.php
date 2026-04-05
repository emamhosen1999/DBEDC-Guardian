<?php

namespace App\Http\Controllers;

use App\Models\DMS\Category;
use App\Models\DMS\Document;
use App\Models\DMS\DocumentAccessLog;
use App\Models\DMS\DocumentShare;
use App\Models\DMS\DocumentVersion;
use App\Models\DMS\Folder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DMSController extends Controller
{
    public function index()
    {
        return Inertia::render('DMS/Index', [
            'page' => 'dashboard',
            'stats' => [
                'total_documents' => Document::count(),
                'published_documents' => Document::where('status', 'published')->count(),
                'shared_documents' => DocumentShare::active()->count(),
                'total_categories' => Category::count(),
                'total_folders' => Folder::count(),
            ],
            'recentDocuments' => Document::with(['category', 'creator'])
                ->latest()
                ->take(10)
                ->get(),
        ]);
    }

    public function documents(Request $request)
    {
        $query = Document::query()
            ->with(['category', 'creator'])
            ->latestVersions();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $documents = $query->paginate(15)->withQueryString();

        return Inertia::render('DMS/Index', [
            'page' => 'documents',
            'documents' => $documents,
            'filters' => $request->only(['search', 'category_id', 'status']),
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => ['draft', 'pending_review', 'approved', 'published', 'archived', 'expired'],
        ]);
    }

    public function show(Document $document)
    {
        $document->load([
            'category',
            'creator',
            'updater',
            'approver',
            'folders',
            'shares.sharedWith',
            'shares.sharedBy',
            'versionHistory.creator',
        ]);

        DocumentAccessLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'view',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => ['source' => 'dms.show'],
        ]);

        return Inertia::render('DMS/Index', [
            'page' => 'show',
            'document' => $document,
            'users' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function download(Document $document)
    {
        if (! Storage::exists($document->file_path)) {
            return back()->with('error', 'Document file not found.');
        }

        DocumentAccessLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'download',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => ['source' => 'dms.download'],
        ]);

        return Storage::download($document->file_path, $document->original_file_name);
    }

    public function shared()
    {
        $shares = DocumentShare::query()
            ->with(['document', 'sharedBy', 'sharedWith'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('DMS/Index', [
            'page' => 'shared',
            'shares' => $shares,
        ]);
    }

    public function analytics()
    {
        $actions = DocumentAccessLog::query()
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        return Inertia::render('DMS/Index', [
            'page' => 'analytics',
            'analytics' => [
                'total_documents' => Document::count(),
                'latest_versions' => Document::latestVersions()->count(),
                'access_actions' => $actions,
                'recent_access' => DocumentAccessLog::with(['document', 'user'])
                    ->latest()
                    ->take(20)
                    ->get(),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('DMS/Index', [
            'page' => 'create',
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'folders' => Folder::query()->root()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:dms_categories,id',
            'document' => 'required|file|max:20480',
            'visibility' => 'nullable|in:public,internal,restricted,confidential',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string',
            'folder_ids' => 'nullable|array',
            'folder_ids.*' => 'exists:dms_folders,id',
        ]);

        $uploadedFile = $request->file('document');
        $storedPath = $uploadedFile->store('dms_documents');

        $document = Document::create([
            'title' => $validated['title'],
            'document_number' => $this->generateDocumentNumber(),
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'],
            'file_name' => basename($storedPath),
            'original_file_name' => $uploadedFile->getClientOriginalName(),
            'file_path' => $storedPath,
            'file_type' => strtolower($uploadedFile->getClientOriginalExtension()),
            'file_size' => $uploadedFile->getSize(),
            'mime_type' => $uploadedFile->getClientMimeType(),
            'checksum' => hash_file('sha256', $uploadedFile->getRealPath()),
            'tags' => $validated['tags'] ?? [],
            'keywords' => $validated['keywords'] ?? [],
            'custom_fields' => [],
            'version' => '1.0',
            'parent_document_id' => null,
            'is_latest_version' => true,
            'status' => 'draft',
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
            'visibility' => $validated['visibility'] ?? 'internal',
            'access_permissions' => [],
            'search_content' => null,
            'is_searchable' => true,
        ]);

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => '1.0',
            'change_summary' => 'Initial upload',
            'file_path' => $document->file_path,
            'file_size' => $document->file_size,
            'checksum' => $document->checksum,
            'created_by' => Auth::id(),
        ]);

        if (! empty($validated['folder_ids'])) {
            $document->folders()->syncWithPivotValues($validated['folder_ids'], ['added_by' => Auth::id()]);
        }

        return redirect()->route('dms.documents')->with('success', 'Document uploaded successfully.');
    }

    public function update(Request $request, Document $document)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:dms_categories,id',
            'status' => 'nullable|in:draft,pending_review,approved,published,archived,expired',
            'visibility' => 'nullable|in:public,internal,restricted,confidential',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'keywords' => 'nullable|array',
            'keywords.*' => 'string',
            'document' => 'nullable|file|max:20480',
            'change_summary' => 'nullable|string|max:500',
        ]);

        $updateData = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'],
            'status' => $validated['status'] ?? $document->status,
            'visibility' => $validated['visibility'] ?? $document->visibility,
            'tags' => $validated['tags'] ?? [],
            'keywords' => $validated['keywords'] ?? [],
            'updated_by' => Auth::id(),
        ];

        if ($request->hasFile('document')) {
            $uploadedFile = $request->file('document');
            $storedPath = $uploadedFile->store('dms_documents');

            $nextVersion = (string) (round((float) $document->version + 0.1, 1));
            $checksum = hash_file('sha256', $uploadedFile->getRealPath());

            $updateData = array_merge($updateData, [
                'file_name' => basename($storedPath),
                'original_file_name' => $uploadedFile->getClientOriginalName(),
                'file_path' => $storedPath,
                'file_type' => strtolower($uploadedFile->getClientOriginalExtension()),
                'file_size' => $uploadedFile->getSize(),
                'mime_type' => $uploadedFile->getClientMimeType(),
                'checksum' => $checksum,
                'version' => $nextVersion,
            ]);

            DocumentVersion::create([
                'document_id' => $document->id,
                'version' => $nextVersion,
                'change_summary' => $validated['change_summary'] ?? 'Document file updated',
                'file_path' => $storedPath,
                'file_size' => $uploadedFile->getSize(),
                'checksum' => $checksum,
                'created_by' => Auth::id(),
            ]);
        }

        $document->update($updateData);

        return redirect()->route('dms.documents.show', $document)->with('success', 'Document updated successfully.');
    }

    public function share(Request $request, Document $document)
    {
        $validated = $request->validate([
            'shared_with' => 'nullable|exists:users,id',
            'permission' => 'required|in:view,comment,edit',
            'expires_at' => 'nullable|date|after:now',
        ]);

        DocumentShare::create([
            'document_id' => $document->id,
            'shared_by' => Auth::id(),
            'shared_with' => $validated['shared_with'] ?? null,
            'share_token' => ! empty($validated['shared_with']) ? null : (string) Str::uuid(),
            'permission' => $validated['permission'],
            'expires_at' => $validated['expires_at'] ?? null,
            'is_active' => true,
            'download_count' => 0,
            'view_count' => 0,
        ]);

        return back()->with('success', 'Document shared successfully.');
    }

    public function destroy(Document $document)
    {
        if ($document->file_path && Storage::exists($document->file_path)) {
            Storage::delete($document->file_path);
        }

        $document->delete();

        return redirect()->route('dms.documents')->with('success', 'Document deleted successfully.');
    }

    public function categories()
    {
        return Inertia::render('DMS/Index', [
            'page' => 'categories',
            'categories' => Category::withCount('documents')->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:100|unique:dms_categories,code',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:dms_categories,id',
        ]);

        $code = $validated['code'] ?? Str::upper(Str::slug($validated['name'], '_'));

        if (Category::where('code', $code)->exists()) {
            $code .= '_'.Str::upper(Str::random(4));
        }

        Category::create([
            'name' => $validated['name'],
            'code' => $code,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#3B82F6',
            'icon' => $validated['icon'] ?? null,
            'allowed_file_types' => null,
            'max_file_size' => 10240,
            'retention_period' => null,
            'requires_approval' => false,
            'is_active' => true,
            'sort_order' => 0,
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        return back()->with('success', 'Category created successfully.');
    }

    public function updateCategory(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:dms_categories,code,'.$category->id,
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:dms_categories,id',
            'is_active' => 'nullable|boolean',
        ]);

        $category->update([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? $category->color,
            'icon' => $validated['icon'] ?? $category->icon,
            'parent_id' => $validated['parent_id'] ?? null,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $category->is_active,
        ]);

        return back()->with('success', 'Category updated successfully.');
    }

    public function destroyCategory(Category $category)
    {
        if ($category->documents()->exists()) {
            return back()->with('error', 'Cannot delete category with linked documents.');
        }

        $category->delete();

        return back()->with('success', 'Category deleted successfully.');
    }

    public function folders()
    {
        return Inertia::render('DMS/Index', [
            'page' => 'folders',
            'folders' => Folder::withCount('documents')->with('parent')->orderBy('name')->get(),
        ]);
    }

    public function storeFolder(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'parent_id' => 'nullable|exists:dms_folders,id',
            'is_shared' => 'nullable|boolean',
        ]);

        Folder::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#6B7280',
            'parent_id' => $validated['parent_id'] ?? null,
            'created_by' => Auth::id(),
            'access_permissions' => [],
            'is_shared' => (bool) ($validated['is_shared'] ?? false),
        ]);

        return back()->with('success', 'Folder created successfully.');
    }

    public function updateFolder(Request $request, Folder $folder)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:20',
            'parent_id' => 'nullable|exists:dms_folders,id',
            'is_shared' => 'nullable|boolean',
        ]);

        $folder->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? $folder->color,
            'parent_id' => $validated['parent_id'] ?? null,
            'is_shared' => (bool) ($validated['is_shared'] ?? false),
        ]);

        return back()->with('success', 'Folder updated successfully.');
    }

    public function destroyFolder(Folder $folder)
    {
        if ($folder->documents()->exists()) {
            return back()->with('error', 'Cannot delete folder containing documents.');
        }

        $folder->delete();

        return back()->with('success', 'Folder deleted successfully.');
    }

    public function accessControl()
    {
        return Inertia::render('DMS/Index', [
            'page' => 'access-control',
            'users' => User::select('id', 'name', 'email')->orderBy('name')->get(),
            'documents' => Document::select('id', 'title', 'document_number')->latest()->take(100)->get(),
            'folders' => Folder::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function updateAccessControl(Request $request)
    {
        $validated = $request->validate([
            'document_id' => 'nullable|exists:dms_documents,id',
            'folder_id' => 'nullable|exists:dms_folders,id',
            'access_permissions' => 'required|array',
        ]);

        if (! empty($validated['document_id'])) {
            $document = Document::findOrFail($validated['document_id']);
            $document->update(['access_permissions' => $validated['access_permissions']]);
        }

        if (! empty($validated['folder_id'])) {
            $folder = Folder::findOrFail($validated['folder_id']);
            $folder->update(['access_permissions' => $validated['access_permissions']]);
        }

        if (empty($validated['document_id']) && empty($validated['folder_id'])) {
            return back()->with('error', 'Select a document or folder first.');
        }

        return back()->with('success', 'Access control updated successfully.');
    }

    private function generateDocumentNumber(): string
    {
        do {
            $number = 'DOC-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Document::where('document_number', $number)->exists());

        return $number;
    }
}
