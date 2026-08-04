<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class PrintifyProductResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'printify_product_id'=>$this->printify_product_id,'title'=>$this->title,'status'=>$this->status,'blueprint_id'=>$this->blueprint_id,'print_provider_id'=>$this->print_provider_id,'synced_at'=>$this->synced_at,'variants'=>PrintifyVariantResource::collection($this->whenLoaded('variants'))]; } }
