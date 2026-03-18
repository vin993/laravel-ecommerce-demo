<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CmsHomeContent;
use App\Repositories\CmsHomeContentRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class CmsHomeContentController extends Controller
{
    protected $repository;

    public function __construct(CmsHomeContentRepository $repository)
    {
        $this->repository = $repository;
    }

    public function index()
    {
        $contents = $this->repository->all();
        return view('admin.cms.home.index', compact('contents'));
    }

    public function create()
    {
        $allSections = $this->getAvailableSections();
        $existingKeys = CmsHomeContent::pluck('section_key')->toArray();

        $sectionKeys = array_diff_key($allSections, array_flip($existingKeys));

        if (empty($sectionKeys)) {
            return redirect()->route('admin.cms.home.index')
                ->with('warning', 'All available sections have already been created. You can edit existing sections instead.');
        }

        return view('admin.cms.home.create', compact('sectionKeys'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'section_key' => 'required|string|unique:cms_home_content,section_key',
            'title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string',
            'content' => 'nullable|string',
            'link_url' => 'nullable|string|max:255',
            'link_text' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'status' => 'nullable',
        ]);

        $validated['status'] = $request->has('status') ? 1 : 0;
        $validated['sort_order'] = 0;

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('cms/home', 'public');
            $validated['image_path'] = $path;
        }

        $this->repository->create($validated);

        Cache::forget('cms_home_content_all');

        return redirect()->route('admin.cms.home.index')
            ->with('success', 'Home content created successfully.');
    }

    public function edit($id)
    {
        $content = $this->repository->find($id);
        $sectionKeys = $this->getAvailableSections();
        return view('admin.cms.home.edit', compact('content', 'sectionKeys'));
    }

    public function update(Request $request, $id)
    {
        $content = $this->repository->find($id);

        $validated = $request->validate([
            'section_key' => 'required|string|unique:cms_home_content,section_key,' . $id,
            'title' => 'nullable|string',
            'subtitle' => 'nullable|string',
            'content' => 'nullable|string',
            'link_url' => 'nullable|string',
            'link_text' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'status' => 'nullable',
        ]);

        $updateData = [
            'section_key' => $validated['section_key'],
            'title' => $request->input('title'),
            'subtitle' => $request->input('subtitle'),
            'content' => $request->input('content'),
            'link_url' => $request->input('link_url'),
            'link_text' => $request->input('link_text'),
            'status' => $request->has('status') ? 1 : 0,
            'sort_order' => 0,
        ];

        if ($request->hasFile('image')) {
            if ($content->image_path && Storage::disk('public')->exists($content->image_path)) {
                Storage::disk('public')->delete($content->image_path);
            }
            $path = $request->file('image')->store('cms/home', 'public');
            $updateData['image_path'] = $path;
        }

        $this->repository->update($id, $updateData);

        Cache::forget('cms_home_content_all');
        Cache::forget('cms_home_content_' . $content->section_key);

        return redirect()->route('admin.cms.home.index')
            ->with('success', 'Home content updated successfully.');
    }

    public function delete($id)
    {
        $content = $this->repository->find($id);

        if ($content->image_path) {
            Storage::disk('public')->delete($content->image_path);
        }

        $this->repository->delete($id);

        Cache::forget('cms_home_content_all');
        Cache::forget('cms_home_content_' . $content->section_key);

        return redirect()->route('admin.cms.home.index')
            ->with('success', 'Home content deleted successfully.');
    }

    public function clearCache()
    {
        $this->repository->clearCache();

        return redirect()->route('admin.cms.home.index')
            ->with('success', 'Home content cache cleared successfully.');
    }

    protected function getAvailableSections()
    {
        return [
            'hero_section' => 'Hero Section',
            'about_section' => 'About Section',
            'shop_now_1' => 'Shop Now Section 1',
            'shop_now_2' => 'Shop Now Section 2',
            'support_section' => 'Support Section',
            'ultimate_sale' => 'Ultimate Sale Section',
            'newsletter_section' => 'Newsletter Section',
        ];
    }
}
