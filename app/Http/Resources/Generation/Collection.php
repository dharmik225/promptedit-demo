<?php

namespace App\Http\Resources\Generation;

use Illuminate\Http\Resources\Json\ResourceCollection;

class Collection extends ResourceCollection
{
    public $collects = Resource::class;
}
