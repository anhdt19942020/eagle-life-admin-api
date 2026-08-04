<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class PrintifyVariantResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'printify_variant_id'=>$this->printify_variant_id,'sku'=>$this->sku,'title'=>$this->title,'is_enabled'=>$this->is_enabled,'price'=>$this->price]; } }
