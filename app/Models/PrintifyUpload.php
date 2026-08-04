<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PrintifyUpload extends Model { protected $fillable = ['printify_upload_id','file_name','preview_url','synced_at']; protected $casts = ['synced_at'=>'datetime']; }
