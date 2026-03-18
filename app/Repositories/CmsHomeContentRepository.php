<?php

namespace App\Repositories;

use App\Models\CmsHomeContent;
use Illuminate\Support\Facades\Cache;

class CmsHomeContentRepository
{
    protected $model;

    public function __construct(CmsHomeContent $model)
    {
        $this->model = $model;
    }

    public function all()
    {
        return $this->model->orderBy('id')->get();
    }

    public function getAllActive()
    {
        return CmsHomeContent::getAllActive();
    }

    public function getByKey($key)
    {
        return CmsHomeContent::getByKey($key);
    }

    public function find($id)
    {
        return $this->model->findOrFail($id);
    }

    public function create(array $data)
    {
        return $this->model->create($data);
    }

    public function update($id, array $data)
    {
        $content = $this->find($id);
        $content->update($data);
        return $content;
    }

    public function delete($id)
    {
        $content = $this->find($id);
        return $content->delete();
    }

    public function clearCache()
    {
        Cache::forget('cms_home_content_all');

        $allContents = $this->model->all();
        foreach ($allContents as $content) {
            Cache::forget('cms_home_content_' . $content->section_key);
        }
    }
}
