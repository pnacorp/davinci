<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Statistics\UserService;
use App\Services\FalAI;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Orhanerday\OpenAi\OpenAi;
use App\Models\SubscriptionPlan;
use App\Models\Image;
use App\Models\User;
use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\ImagePrompt;
use App\Models\MainSetting;
use App\Models\ImageCredit;
use App\Services\Service;

class ImageController extends Controller
{
    private $user;

    public function __construct()
    {
        $this->user = new UserService();
    }

    /** 
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {   
        $data = Image::where('user_id', Auth::user()->id)->latest()->limit(18)->get();
        $records = Image::where('user_id', Auth::user()->id)->count();
        $prompts = ImagePrompt::where('status', true)->get();
        $settings = MainSetting::first();

        if (auth()->user()->plan_id) {
            $plan = SubscriptionPlan::where('id', auth()->user()->plan_id)->first();
            if ($plan) {
                if (!is_null($plan->image_vendors)) {
                    $vendors = explode(',', $plan->image_vendors); 
                    $vendor = null;
                } else {
                    $vendors = ['openai'];
                    $vendor = 'openai';
                }
            } else {
                $vendors = ['openai'];
                $vendor = 'openai';
            }
        } else {
            if (!is_null($settings->image_vendors)) {
                $vendors = explode(',', $settings->image_vendors);
                $vendor = null;
            } else {
                $vendors = ['openai'];
                $vendor = 'openai';
            }
        }       

        $selected_vendor = head($vendors);
        switch ($selected_vendor) {
            case 'openai':
                $model = 'dall-e-3';
                $model_name = 'OpenAI DALLE 3';
                break;
            case 'sd':
                $model = 'sd3-large';
                $model_name = 'Stable Diffusion 3 Large';
                break;
            case 'falai':
                $model = 'flux-pro/new';
                $model_name = 'FLUX.1 [pro]';
                break;
            default:
                $model = 'dall-e-3';
                $model_name = 'OpenAI DALLE 3';
                break;
        }

        $credits = ImageCredit::first();

        return view('user.images.index', compact('data', 'records', 'prompts', 'vendors', 'vendor', 'model', 'model_name', 'credits'));
    }


    /**
	*
	* Process Davinci Image
	* @param - file id in DB
	* @return - confirmation
	*
	*/
	public function process(Request $request) 
    {
        if ($request->ajax()) {

            if (config('settings.personal_openai_api') == 'allow') {
                if (is_null(auth()->user()->personal_openai_key)) {
                    $data['status'] = 'error';
                    $data['message'] = __('You must include your personal Openai API key in your profile settings first');
                    return $data; 
                } else {
                    $open_ai = new OpenAi(auth()->user()->personal_openai_key);
                } 
    
            } elseif (!is_null(auth()->user()->plan_id)) {
                $check_api = SubscriptionPlan::where('id', auth()->user()->plan_id)->first();
                if ($check_api->personal_openai_api) {
                    if (is_null(auth()->user()->personal_openai_key)) {
                        $data['status'] = 'error';
                        $data['message'] = __('You must include your personal Openai API key in your profile settings first');
                        return $data; 
                    } else {
                        $open_ai = new OpenAi(auth()->user()->personal_openai_key);
                    }
                } else {
                    if (config('settings.openai_key_usage') !== 'main') {
                       $api_keys = ApiKey::where('engine', 'openai')->where('status', true)->pluck('api_key')->toArray();
                       array_push($api_keys, config('services.openai.key'));
                       $key = array_rand($api_keys, 1);
                       $open_ai = new OpenAi($api_keys[$key]);
                   } else {
                       $open_ai = new OpenAi(config('services.openai.key'));
                   }
               }
    
            } else {
                if (config('settings.openai_key_usage') !== 'main') {
                    $api_keys = ApiKey::where('engine', 'openai')->where('status', true)->pluck('api_key')->toArray();
                    array_push($api_keys, config('services.openai.key'));
                    $key = array_rand($api_keys, 1);
                    $open_ai = new OpenAi($api_keys[$key]);
                } else {
                    $open_ai = new OpenAi(config('services.openai.key'));
                }
            }

            $plan = SubscriptionPlan::where('id', auth()->user()->plan_id)->first();
            $results = [];

            # Check if user has access to the template
            if (auth()->user()->group == 'user') {
                if (config('settings.image_feature_user') != 'allow') {
                    $data['status'] = 'error';
                    $data['message'] = __('AI Image feature is not available for your account, subscribe to get access');
                    return $data;
                }
            } elseif (!is_null(auth()->user()->plan_id)) {
                if ($plan) {
                    if (!$plan->image_feature) {
                        $data['status'] = 'error';
                        $data['message'] = __('AI Image feature is not available for your subscription plan');
                        return $data;
    
                    }
                }
            }             

            # Verify if user has enough credits
            $max_credits = $this->check_credits($request->model);
            if (auth()->user()->image_credits != -1) {
                if ((auth()->user()->image_credits + auth()->user()->image_credits_prepaid) < $max_credits) {
                    if (!is_null(auth()->user()->member_of)) {
                        if (auth()->user()->member_use_credits_image) {
                            $member = User::where('id', auth()->user()->member_of)->first();
                            if (($member->image_credits + $member->image_credits_prepaid) < $max_credits) {
                                $data['status'] = 'error';
                                $data['message'] = __('Not enough image balance to proceed, subscribe or top up your image balance and try again');
                                return $data;
                            }
                        } else {
                            $data['status'] = 'error';
                            $data['message'] = __('Not enough image balance to proceed, subscribe or top up your image balance and try again');
                            return $data;
                        }
                        
                    } else {
                        $data['status'] = 'error';
                        $data['message'] = __('Not enough image balance to proceed, subscribe or top up your image balance and try again');
                        return $data;
                    } 
                }
            }
    

            $max_results = (int)$request->max_results;
            $plan_type = (auth()->user()->plan_id) ? 'paid' : 'free';  

            $prompt = $request->prompt;
            
            if ($request->style != 'none') {
                $prompt .= ', ' . $request->style; 
            } 
            
            if ($request->lightning != 'none') {
                $prompt .= ', ' . $request->lightning; 
            } 
            
            if ($request->artist != 'none') {
                $prompt .= ', ' . $request->artist; 
            }
            
            if ($request->medium != 'none') {
                $prompt .= ', ' . $request->medium; 
            }
            
            if ($request->mood != 'none') {
                $prompt .= ', ' . $request->mood; 
            }


            if ($request->vendor == 'openai') {


                if ($request->model == 'dall-e-3-hd') {
                    $complete = $open_ai->image([
                        'model' => 'dall-e-3',
                        'prompt' => $prompt,
                        'size' => $request->resolution,
                        'n' => $max_results,
                        "response_format" => "url",
                        'quality' => "hd",
                    ]);
                } else {
                    $complete = $open_ai->image([
                        'model' => $request->model,
                        'prompt' => $prompt,
                        'size' => $request->resolution,
                        'n' => $max_results,
                        "response_format" => "url",
                        'quality' => "standard",
                    ]);
                } 
                

                $response = json_decode($complete , true);

                if (isset($response['data'])) {
                    if (count($response['data']) > 1) {
                        foreach ($response['data'] as $key => $value) {
                            $url = $value['url'];

                            $curl = curl_init();
                            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($curl, CURLOPT_URL, $url);
                            $contents = curl_exec($curl);
                            curl_close($curl);

                            $name = 'dalle-' . Str::random(10) . '.png';

                            if (config('settings.default_storage') == 'local') {
                                Storage::disk('public')->put('images/' . $name, $contents);
                                $image_url = 'images/' . $name;
                                $storage = 'local';
                            } elseif (config('settings.default_storage') == 'aws') {
                                Storage::disk('s3')->put('images/' . $name, $contents, 'public');
                                $image_url = Storage::disk('s3')->url('images/' . $name);
                                $storage = 'aws';
                            } elseif (config('settings.default_storage') == 'r2') {
                                Storage::disk('r2')->put('images/' . $name, $contents);
                                $image_url = Storage::disk('r2')->url('images/' . $name);
                                $storage = 'r2';
                            } elseif (config('settings.default_storage') == 'wasabi') {
                                Storage::disk('wasabi')->put('images/' . $name, $contents);
                                $image_url = Storage::disk('wasabi')->url('images/' . $name);
                                $storage = 'wasabi';
                            } elseif (config('settings.default_storage') == 'gcp') {
                                Storage::disk('gcs')->put('images/' . $name, $contents);
                                Storage::disk('gcs')->setVisibility('images/' . $name, 'public');
                                $image_url = Storage::disk('gcs')->url('images/' . $name);
                                $storage = 'gcp';
                            } elseif (config('settings.default_storage') == 'storj') {
                                Storage::disk('storj')->put('images/' . $name, $contents, 'public');
                                Storage::disk('storj')->setVisibility('images/' . $name, 'public');
                                $image_url = Storage::disk('storj')->temporaryUrl('images/' . $name, now()->addHours(167));
                                $storage = 'storj';                        
                            } elseif (config('settings.default_storage') == 'dropbox') {
                                Storage::disk('dropbox')->put('images/' . $name, $contents);
                                $image_url = Storage::disk('dropbox')->url('images/' . $name);
                                $storage = 'dropbox';
                            }

                            $content = new Image();
                            $content->user_id = auth()->user()->id;
                            $content->description = $request->prompt;
                            $content->resolution = $request->resolution;
                            $content->image = $image_url;
                            $content->plan_type = $plan_type;
                            $content->storage = $storage;
                            $content->image_name = 'images/' . $name;
                            $content->vendor = 'openai';
                            $content->image_style = $request->style;
                            $content->image_lighting = $request->lightning;
                            $content->image_artist = $request->artist;
                            $content->image_mood = $request->mood;
                            $content->image_medium = $request->medium;
                            $content->vendor_engine = $request->model;
                            $content->cost = $max_credits;
                            $content->save();

                            $image_result = $this->createImageBox($content);
                            array_push($results, $image_result);
                        }
                    } else {
                        $url = $response['data'][0]['url'];

                        $curl = curl_init();
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        $contents = curl_exec($curl);
                        curl_close($curl);


                        $name = 'dalle-' . Str::random(10) . '.png';

                        if (config('settings.default_storage') == 'local') {
                            Storage::disk('public')->put('images/' . $name, $contents);
                            $image_url = 'images/' . $name;
                            $storage = 'local';
                        } elseif (config('settings.default_storage') == 'aws') {
                            Storage::disk('s3')->put('images/' . $name, $contents, 'public');
                            $image_url = Storage::disk('s3')->url('images/' . $name);
                            $storage = 'aws';
                        } elseif (config('settings.default_storage') == 'r2') {
                            Storage::disk('r2')->put('images/' . $name, $contents, 'public');
                            $image_url = Storage::disk('r2')->url('images/' . $name);
                            $storage = 'r2';
                        } elseif (config('settings.default_storage') == 'wasabi') {
                            Storage::disk('wasabi')->put('images/' . $name, $contents);
                            $image_url = Storage::disk('wasabi')->url('images/' . $name);
                            $storage = 'wasabi';
                        } elseif (config('settings.default_storage') == 'gcp') {
                            Storage::disk('gcs')->put('images/' . $name, $contents);
                            Storage::disk('gcs')->setVisibility('images/' . $name, 'public');
                            $image_url = Storage::disk('gcs')->url('images/' . $name);
                            $storage = 'gcp';
                        } elseif (config('settings.default_storage') == 'storj') {
                            Storage::disk('storj')->put('images/' . $name, $contents, 'public');
                            Storage::disk('storj')->setVisibility('images/' . $name, 'public');
                            $image_url = Storage::disk('storj')->temporaryUrl('images/' . $name, now()->addHours(167));
                            $storage = 'storj';                        
                        } elseif (config('settings.default_storage') == 'dropbox') {
                            Storage::disk('dropbox')->put('images/' . $name, $contents);
                            $image_url = Storage::disk('dropbox')->url('images/' . $name);
                            $storage = 'dropbox';
                        }

                        $content = new Image();
                        $content->user_id = auth()->user()->id;
                        $content->description = $request->prompt;
                        $content->resolution = $request->resolution;
                        $content->image = $image_url;
                        $content->plan_type = $plan_type;
                        $content->storage = $storage;
                        $content->image_name = 'images/' . $name;
                        $content->vendor = 'openai';
                        $content->image_style = $request->style;
                        $content->image_lighting = $request->lightning;
                        $content->image_artist = $request->artist;
                        $content->image_mood = $request->mood;
                        $content->image_medium = $request->medium;
                        $content->vendor_engine = $request->model;
                        $content->cost = $max_credits;
                        $content->save();

                        $image_result = $this->createImageBox($content);
                        array_push($results, $image_result);
                    }
                    
                    # Update credit balance
                    $this->updateBalance($max_results, $max_credits);

                    $data['status'] = 'success';
                    $data['images'] = $results;
                    $data['old'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid;
                    $data['current'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid - $max_credits;
                    $data['balance'] = (auth()->user()->image_credits == -1) ? 'unlimited' : 'counted';
                    $data['task'] = 'dalle';
                    return $data; 

                } else {
                    if ($response['error']['code'] == 'invalid_api_key') {
                        $message = 'Please try again, Dalle 3 model limit has been reached for today.';
                    } else {
                        $message = $response['error']['message'];
                    }
                    

                    $data['status'] = 'error';
                    $data['message'] = $message;
                    return $data;
                }

            } elseif ($request->vendor == 'sd') {

                if (config('settings.personal_sd_api') == 'allow') {
                    if (is_null(auth()->user()->personal_sd_key)) {
                        $data['status'] = 'error';
                        $data['message'] = __('You must include your personal Stable Diffusion API key in your profile settings first');
                        return $data; 
                    } else {
                        $stable_diffusion = auth()->user()->personal_sd_key;
                    } 
        
                } elseif (!is_null(auth()->user()->plan_id)) {
                    $check_api = SubscriptionPlan::where('id', auth()->user()->plan_id)->first();
                    if ($check_api->personal_sd_api) {
                        if (is_null(auth()->user()->personal_sd_key)) {
                            $data['status'] = 'error';
                            $data['message'] = __('You must include your personal Stable Diffusion API key in your profile settings first');
                            return $data; 
                        } else {
                            $stable_diffusion = auth()->user()->personal_sd_key;
                        }
                    } else {
                        if (config('settings.sd_key_usage') == 'main') {
                            $stable_diffusion = config('services.stable_diffusion.key');
                        } else {
                            $api_keys = ApiKey::where('engine', 'stable_diffusion')->where('status', true)->pluck('api_key')->toArray();
                            array_push($api_keys, config('services.stable_diffusion.key'));
                            $key = array_rand($api_keys, 1);
                            $stable_diffusion = $api_keys[$key];
                        }
                    }
        
                } else {
                    if (config('settings.sd_key_usage') == 'main') {
                        $stable_diffusion = config('services.stable_diffusion.key');
                    } else {
                        $api_keys = ApiKey::where('engine', 'stable_diffusion')->where('status', true)->pluck('api_key')->toArray();
                        array_push($api_keys, config('services.stable_diffusion.key'));
                        $key = array_rand($api_keys, 1);
                        $stable_diffusion = $api_keys[$key];
                    }
                }

                $sd_model = $request->model;

                if ($sd_model != 'core' && $sd_model != 'ultra' && $sd_model != 'sd3-large' && $sd_model != 'sd3-large-turbo' && $sd_model != 'sd3-medium') {
                    
                    $url = 'https://api.stability.ai/v1/generation/' . $sd_model;
                    $output = '';

                    if ($request->task != 'none' && $request->task != "sd-multi-prompting" && $request->task != "sd-negative-prompt") {
                        if ($request->task == 'sd-image-to-image') {
    
                            $url .= '/image-to-image';
                            
                            $image_name = request()->file('image')->getClientOriginalName();
                            Storage::disk('audio')->put(request()->file('image')->getClientOriginalName(),request()->file('image')->get());
                            $path = Storage::disk('audio')->path($image_name);
        
                            $ch = curl_init();
                    
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                'Content-Type: multipart/form-data',
                                'Accept: application/json',
                                'Authorization: Bearer '.$stable_diffusion
                            )); 
    
                            $steps = ((int)$request->steps < 10) ? 10 : (int)$request->steps;
                            $image_strength = (float) $request->image_strength/100;
    
                            $postFields = array(
                                'init_image' => new \CURLFile($path),
                                'text_prompts' => array(
                                    0 => array (
                                    'text' => $prompt,
                                    'weight' => 1
                                    )
                                ),
                                'image_strength' => $image_strength,
                                'init_image_mode' => 'IMAGE_STRENGTH',
                                'steps' => $steps,
                                'cfg_scale' => (int)$request->cfg_scale,
                                'clip_guidance_preset' => $request->preset,
                                'samples' => $max_results,
                            );                 
    
                            if ($request->style != 'none') {
                                $style_preset = array('style_preset' => $request->style);
                                array_push($postFields, $style_preset);
                            }
                           
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->build_post_fields($postFields));                                 
    
                            $result = curl_exec($ch);
                            curl_close($ch);
    
                            $response = json_decode($result , true);
    
                            if (!isset($response['artifacts'])) {
                                if ($response['name'] == 'invalid_file_size') {
                                    $data['status'] = 'error';
                                    $data['message'] = __('Upload image is too large, maximum allowed image size is 5MB');
                                    return $data;
                                } elseif ($response['name'] == 'invalid_sdxl_v1_dimensions') {
                                    $data['status'] = 'error';
                                    $data['message'] = $response['message'];
                                    return $data;
                                }
                            } 
    
                        } elseif ($request->task == 'sd-image-upscale') {
    
                            $image_name = request()->file('image')->getClientOriginalName();
    
                            $url = 'https://api.stability.ai/v1/generation/esrgan-v1-x2plus/image-to-image/upscale'; 
    
                            Storage::disk('audio')->put(request()->file('image')->getClientOriginalName(),request()->file('image')->get());
                            $path = Storage::disk('audio')->path($image_name);
    
                            $ch = curl_init();
                    
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                'Content-Type: multipart/form-data',
                                'Accept: application/json',
                                'Authorization: Bearer '.$stable_diffusion
                            ));
                    
                            $postFields = array(
                                'image' => new \CURLFile($path),
                                'width' => '2048'
                            );
                    
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                    
                            $result = curl_exec($ch);
                        
                            curl_close($ch);
    
                            $response = json_decode($result , true);
    
                            if (!isset($response['artifacts'])) {
                                if ($response['name'] == 'invalid_file_size') {
                                    $data['status'] = 'error';
                                    $data['message'] = __('Upload image is too large, maximum allowed image size is 5MB');
                                    return $data;
                                } elseif ($response['name'] == 'invalid_sdxl_v1_dimensions') {
                                    $data['status'] = 'error';
                                    $data['message'] = $response['message'];
                                    return $data;
                                }
                            }
    
                        } elseif ($request->task == 'sd-image-masking') {
    
                            $url .= '/image-to-image/masking';
    
                            $data['mask_source'] = 'INIT_IMAGE_ALPHA';
                            $data['init_image_mode'] = 'IMAGE_STRENGTH';
    
                            $image_name = request()->file('image')->getClientOriginalName();
                            Storage::disk('audio')->put(request()->file('image')->getClientOriginalName(),request()->file('image')->get());
                            $path = Storage::disk('audio')->path($image_name);
    
                            $ch = curl_init();
                    
                            curl_setopt($ch, CURLOPT_URL, $url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                'Content-Type: multipart/form-data',
                                'Accept: application/json',
                                'Authorization: Bearer '.$stable_diffusion
                            )); 
    
                            $steps = ((int)$request->steps < 10) ? 10 : (int)$request->steps;
                            $image_strength = (float) $request->image_strength/100;
    
                            $postFields = array(
                                'init_image' => new \CURLFile($path),
                                'text_prompts' => array(
                                    0 => array (
                                    'text' => $prompt,
                                    'weight' => 1
                                    )
                                ),
                                'mask_source' => 'INIT_IMAGE_ALPHA',
                                'steps' => $steps,
                                'cfg_scale' => (int)$request->cfg_scale,
                                'clip_guidance_preset' => $request->preset,
                                'samples' => $max_results,
                            );                 
    
                            if ($request->style != 'none') {
                                $style_preset = array('style_preset' => $request->style);
                                array_push($postFields, $style_preset);
                            }
                           
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->build_post_fields($postFields));                                 
    
                            $result = curl_exec($ch);
                            curl_close($ch);
    
                            $response = json_decode($result , true);
    
                            if (!isset($response['artifacts'])) {
                                if ($response['name'] == 'invalid_file_size') {
                                    $data['status'] = 'error';
                                    $data['message'] = __('Upload image is too large, maximum allowed image size is 5MB');
                                    return $data;
                                } elseif ($response['name'] == 'invalid_sdxl_v1_dimensions') {
                                    $data['status'] = 'error';
                                    $data['message'] = $response['message'];
                                    return $data;
                                }
                            }
    
                        }
                    } else {
    
                        $url .= '/text-to-image';
    
                        $headers = [
                            'Authorization:' . $stable_diffusion,
                            'Content-Type: application/json',
                        ];
                      
                        $resolutions = explode('x', $request->resolution);
                        $width = $resolutions[0];
                        $height = $resolutions[1];
                        $data['text_prompts'][0]['text'] = $prompt;
                        $data['text_prompts'][0]['weight'] = 1;
                     
                        if ($request->task == "sd-multi-prompting") {
                            foreach ($request->multi_prompt as $key => $input) {                            
                                $index = ++$key;    
                                $data['text_prompts'][$index]['text'] = $input;
                                $data['text_prompts'][$index]['weight'] = 1;
                                $key++;
                            }
                        }
    
                        if (request('enable-negative-prompt') == 'on') {
                            if (!is_null($request->negative_prompt)) {
                                $data['text_prompts'][1]['text'] = $request->negative_prompt;
                                $data['text_prompts'][1]['weight'] = -1;
                            }                    
                        }
                        $steps = ((int)$request->steps < 10) ? 10 : (int)$request->steps;
                        $data['clip_guidance_preset'] = $request->preset;
                        $data['height'] = (int)$height; 
                        $data['width'] = (int)$width; 
                        $data['steps'] = $steps; 
                        $data['cfg_scale'] = (int)$request->cfg_scale; 
                        if ($request->diffusion_samples != 'none') {
                            $data['sampler'] = $request->diffusion_samples;
                        }
                        $data['samples'] = $max_results;
                        if ($request->style != 'none') {
                            $data['style_preset'] = $request->style;
                        }
    
                        $postdata = json_encode($data);
    
                        $ch = curl_init($url); 
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        $result = curl_exec($ch);
                        curl_close($ch);
    
                        $response = json_decode($result , true);
                    }
    
        
                    if (isset($response['artifacts'])) {
                        foreach ($response['artifacts'] as $key => $value) {
    
                            $image = base64_decode($value['base64']);
    
                            $name = 'sd-' . Str::random(10) . '.png';
    
                            if (config('settings.default_storage') == 'local') {
                                Storage::disk('public')->put('images/' . $name, $image);
                                $image_url = 'images/' . $name;
                                $storage = 'local';
                            } elseif (config('settings.default_storage') == 'aws') {
                                Storage::disk('s3')->put('images/' . $name, $image, 'public');
                                $image_url = Storage::disk('s3')->url('images/' . $name);
                                $storage = 'aws';
                            } elseif (config('settings.default_storage') == 'r2') {
                                Storage::disk('r2')->put('images/' . $name, $image, 'public');
                                $image_url = Storage::disk('r2')->url('images/' . $name);
                                $storage = 'r2';
                            } elseif (config('settings.default_storage') == 'wasabi') {
                                Storage::disk('wasabi')->put('images/' . $name, $image);
                                $image_url = Storage::disk('wasabi')->url('images/' . $name);
                                $storage = 'wasabi';
                            } elseif (config('settings.default_storage') == 'gcp') {
                                Storage::disk('gcs')->put('images/' . $name, $image);
                                Storage::disk('gcs')->setVisibility('images/' . $name, 'public');
                                $image_url = Storage::disk('gcs')->url('images/' . $name);
                                $storage = 'gcp';
                            } elseif (config('settings.default_storage') == 'storj') {
                                Storage::disk('storj')->put('images/' . $name, $image, 'public');
                                Storage::disk('storj')->setVisibility('images/' . $name, 'public');
                                $image_url = Storage::disk('storj')->temporaryUrl('images/' . $name, now()->addHours(167));
                                $storage = 'storj';                        
                            } elseif (config('settings.default_storage') == 'dropbox') {
                                Storage::disk('dropbox')->put('images/' . $name, $image);
                                $image_url = Storage::disk('dropbox')->url('images/' . $name);
                                $storage = 'dropbox';
                            }
    
                            $content = new Image();
                            $content->user_id = auth()->user()->id;
                            $content->description = $request->prompt;
                            $content->resolution = $request->resolution;
                            $content->image = $image_url;
                            $content->plan_type = $plan_type;
                            $content->storage = $storage;
                            $content->image_name = 'images/' . $name;
                            $content->vendor = 'sd';
                            $content->image_style = $request->style;
                            $content->image_lighting = $request->lightning;
                            $content->image_artist = $request->artist;
                            $content->image_mood = $request->mood;
                            $content->image_medium = $request->medium;
                            $content->negative_prompt = $request->negative_prompt;
                            $content->sd_clip_guidance = $request->preset;
                            $content->sd_prompt_strength = $request->cfg_scale;
                            $content->sd_diffusion_samples = $request->diffusion_samples;
                            $content->sd_steps = $request->steps;
                            $content->vendor_engine = $sd_model;
                            $content->cost = $max_credits;
                            $content->save();
    
                            $image_result = $this->createImageBox($content);
                            array_push($results, $image_result);
    
                        }
    
                        # Update credit balance
                        $this->updateBalance($max_results, $max_credits);
    
                        $data['status'] = 'success';
                        $data['images'] = $results;
                        $data['old'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid;
                        $data['current'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid - $max_credits;
                        $data['balance'] = (auth()->user()->image_credits == -1) ? 'unlimited' : 'counted';
                        $data['task'] = 'sd';
                        return $data; 
    
                    } else {
    
                        if (isset($response['name'])) {
                            if ($response['name'] == 'insufficient_balance') {
                                $message = __('You do not have sufficent balance in your Stable Diffusion account to generate new images');
                            } else {
                                $message =  __('There was an issue generating your AI Image, please try again or contact support team');
                            }
                        } else {
                           $message = __('There was an issue generating your AI Image, please try again or contact support team');
                        }
    
    
                        $data['status'] = 'error';
                        $data['message'] = $message;
                        return $data;
                    }

                } else {

                    $sd_mode = ($sd_model == 'core' || $sd_model == 'ultra') ? $sd_model : 'sd3';

                    $url = 'https://api.stability.ai/v2beta/stable-image/generate/' . $sd_mode;

                    $headers = [
                        'Authorization:' . $stable_diffusion,
                        'Content-Type: multipart/form-data',
                        'Accept: application/json',
                    ];

                    if ($sd_model == 'sd3-medium' || $sd_model == 'sd3-large' || $sd_model == 'sd3-large-turbo') {

                        if (request('enable-negative-prompt') == 'on' && $sd_model !== 'sd3-large-turbo') {
                            if (!is_null($request->negative_prompt)) {
                                $postFields = array(
                                    'prompt' => $prompt,
                                    'model' => $sd_model,
                                    'aspect_ratio' => $request->resolution,
                                    'negative_prompt' => $request->negative_prompt,
                                ); 
                            } else {
                                $postFields = array(
                                    'prompt' => $prompt,
                                    'model' => $sd_model,
                                    'aspect_ratio' => $request->resolution,
                                ); 
                            }                   
                        } else {
                            $postFields = array(
                                'prompt' => $prompt,
                                'model' => $sd_model,
                                'aspect_ratio' => $request->resolution,
                            ); 
                        }

                    } elseif($sd_model == 'core') {

                        if ($request->style != 'none') {
                            $postFields = array(
                                'prompt' => $prompt,
                                'aspect_ratio' => $request->resolution,
                                'style_preset' => $request->style
                            ); 
                        } else {
                            $postFields = array(
                                'prompt' => $prompt,
                                'aspect_ratio' => $request->resolution,
                            ); 
                        }
                    } elseif($sd_model == 'ultra') {

                        $postFields = array(
                            'prompt' => $prompt,
                            'aspect_ratio' => $request->resolution,
                        ); 
                    }
 
                    $ch = curl_init($url); 
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->build_post_fields($postFields));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    $result = curl_exec($ch);
                    curl_close($ch);

                    $response = json_decode($result , true);
         
                    if (isset($response['finish_reason'])) {
                        if ($response['finish_reason'] == 'SUCCESS' || $response['finish_reason'] == 'CONTENT_FILTERED') {
    
                            $image = base64_decode($response['image']);
    
                            $name = 'sd-' . Str::random(10) . '.png';
    
                            if (config('settings.default_storage') == 'local') {
                                Storage::disk('public')->put('images/' . $name, $image);
                                $image_url = 'images/' . $name;
                                $storage = 'local';
                            } elseif (config('settings.default_storage') == 'aws') {
                                Storage::disk('s3')->put('images/' . $name, $image, 'public');
                                $image_url = Storage::disk('s3')->url('images/' . $name);
                                $storage = 'aws';
                            } elseif (config('settings.default_storage') == 'r2') {
                                Storage::disk('r2')->put('images/' . $name, $image, 'public');
                                $image_url = Storage::disk('r2')->url('images/' . $name);
                                $storage = 'r2';
                            } elseif (config('settings.default_storage') == 'wasabi') {
                                Storage::disk('wasabi')->put('images/' . $name, $image);
                                $image_url = Storage::disk('wasabi')->url('images/' . $name);
                                $storage = 'wasabi';
                            } elseif (config('settings.default_storage') == 'gcp') {
                                Storage::disk('gcs')->put('images/' . $name, $image);
                                Storage::disk('gcs')->setVisibility('images/' . $name, 'public');
                                $image_url = Storage::disk('gcs')->url('images/' . $name);
                                $storage = 'gcp';
                            } elseif (config('settings.default_storage') == 'storj') {
                                Storage::disk('storj')->put('images/' . $name, $image, 'public');
                                Storage::disk('storj')->setVisibility('images/' . $name, 'public');
                                $image_url = Storage::disk('storj')->temporaryUrl('images/' . $name, now()->addHours(167));
                                $storage = 'storj';                        
                            } elseif (config('settings.default_storage') == 'dropbox') {
                                Storage::disk('dropbox')->put('images/' . $name, $image);
                                $image_url = Storage::disk('dropbox')->url('images/' . $name);
                                $storage = 'dropbox';
                            }

    
                            $content = new Image();
                            $content->user_id = auth()->user()->id;
                            $content->description = $request->prompt;
                            $content->resolution = $request->resolution; 
                            $content->image = $image_url;
                            $content->plan_type = $plan_type;
                            $content->storage = $storage;
                            $content->image_name = 'images/' . $name;
                            $content->vendor = 'sd';
                            $content->image_style = $request->style;
                            $content->image_lighting = $request->lightning;
                            $content->image_artist = $request->artist;
                            $content->image_mood = $request->mood;
                            $content->image_medium = $request->medium;
                            $content->negative_prompt = $request->negative_prompt;
                            $content->sd_clip_guidance = $request->preset;
                            $content->sd_prompt_strength = $request->cfg_scale;
                            $content->sd_diffusion_samples = $request->diffusion_samples;
                            $content->sd_steps = $request->steps;
                            $content->vendor_engine = $sd_model;
                            $content->cost = $max_credits;
                            $content->save();
    
                            $image_result = $this->createImageBox($content);
                            array_push($results, $image_result);

                            # Update credit balance
                            $this->updateBalance($max_results, $max_credits);
        
                            $data['status'] = 'success';
                            $data['images'] = $results;
                            $data['old'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid;
                            $data['current'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid - $max_credits;
                            $data['balance'] = (auth()->user()->image_credits == -1) ? 'unlimited' : 'counted';
                            $data['task'] = 'sd';
                            return $data; 
    
                        }
    
                        
                    } else {
    
                        if (isset($response['name'])) {
                            if ($response['name'] == 'insufficient_balance') {
                                $message = __('You do not have sufficent balance in your Stable Diffusion account to generate new images');
                            } elseif (($response['name'] == 'content_moderation')) {
                                $message =  __('Your request was flagged by SD content moderation system, as a result your request was denied');
                            } else {
                                $message =  __('There was an issue generating your AI Image, please try again or contact support team');
                            }
                        } else {
                           $message = __('There was an issue generating your AI Image, please try again or contact support team');
                        }
    
    
                        $data['status'] = 'error';
                        $data['message'] = $message;
                        return $data;
                    }

                }

            } elseif ($request->vendor == 'falai') {

                $flux = new FalAI;
                $settings = MainSetting::first();

                $result_id = $flux->generate($request->prompt, $request->model);

                if ($result_id) {

                    do {
                        $result = $flux->status($result_id);
  
                    } while ($result == 'IN_PROGRESS' || $result == 'IN_QUEUE');
                    
                    if ($result == 'COMPLETED') {
                        $result = $flux->get($result_id);

                        $url = data_get($result, 'image.url');

                        $curl = curl_init();
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                        curl_setopt($curl, CURLOPT_URL, $url);
                        $contents = curl_exec($curl);
                        curl_close($curl);


                        $name = 'flux-' . Str::random(10) . '.png';

                        if (config('settings.default_storage') == 'local') {
                            Storage::disk('public')->put('images/' . $name, $contents);
                            $image_url = 'images/' . $name;
                            $storage = 'local';
                        } elseif (config('settings.default_storage') == 'aws') {
                            Storage::disk('s3')->put('images/' . $name, $contents, 'public');
                            $image_url = Storage::disk('s3')->url('images/' . $name);
                            $storage = 'aws';
                        } elseif (config('settings.default_storage') == 'r2') {
                            Storage::disk('r2')->put('images/' . $name, $contents);
                            $image_url = Storage::disk('r2')->url('images/' . $name);
                            $storage = 'r2';
                        } elseif (config('settings.default_storage') == 'wasabi') {
                            Storage::disk('wasabi')->put('images/' . $name, $contents);
                            $image_url = Storage::disk('wasabi')->url('images/' . $name);
                            $storage = 'wasabi';
                        } elseif (config('settings.default_storage') == 'gcp') {
                            Storage::disk('gcs')->put('images/' . $name, $contents);
                            Storage::disk('gcs')->setVisibility('images/' . $name, 'public');
                            $image_url = Storage::disk('gcs')->url('images/' . $name);
                            $storage = 'gcp';
                        } elseif (config('settings.default_storage') == 'storj') {
                            Storage::disk('storj')->put('images/' . $name, $contents, 'public');
                            Storage::disk('storj')->setVisibility('images/' . $name, 'public');
                            $image_url = Storage::disk('storj')->temporaryUrl('images/' . $name, now()->addHours(167));
                            $storage = 'storj';                        
                        } elseif (config('settings.default_storage') == 'dropbox') {
                            Storage::disk('dropbox')->put('images/' . $name, $contents);
                            $image_url = Storage::disk('dropbox')->url('images/' . $name);
                            $storage = 'dropbox';
                        }

                        $resolution = $request->resolution;
                        switch ($request->resolution) {
                            case 'square_hd': $resolution = 'Square HD'; break;
                            case 'square': $resolution = 'Square'; break;
                            case 'portrait_4_3': $resolution = 'Portrait 4:3'; break;
                            case 'portrait_16_9': $resolution = 'Portrait 16:9'; break;
                            case 'landscape_4_3': $resolution = 'Landscape 4:3'; break;
                            case 'landscape_16_9': $resolution = 'Landscape 16:9'; break;
                        }

                        $content = new Image();
                        $content->user_id = auth()->user()->id;
                        $content->description = $request->prompt;
                        $content->resolution = $resolution;
                        $content->image = $image_url;
                        $content->plan_type = $plan_type;
                        $content->storage = $storage;
                        $content->image_name = 'images/' . $name;
                        $content->vendor = 'falai';
                        $content->image_style = $request->style;
                        $content->image_lighting = $request->lightning;
                        $content->image_artist = $request->artist;
                        $content->image_mood = $request->mood;
                        $content->image_medium = $request->medium;
                        $content->vendor_engine = $request->model;
                        $content->cost = $max_credits;
                        $content->save();


                        $image_result = $this->createImageBox($content);
                        array_push($results, $image_result);

                        # Update credit balance
                        $this->updateBalance($max_results, $max_credits);

                        $data['status'] = 'success';
                        $data['images'] = $results;
                        $data['old'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid;
                        $data['current'] = auth()->user()->image_credits + auth()->user()->image_credits_prepaid - $max_credits;
                        $data['balance'] = (auth()->user()->image_credits == -1) ? 'unlimited' : 'counted';
                        $data['task'] = 'flux';
                        return $data; 
                    }


                } else {
                    $data['status'] = 'error';
                    $data['message'] = __('There was an issue generating your Flux image');
                    return $data;
                }

            }
           

        }
	}


    /**
	*
	* Update user image balance
	* @param - total words generated
	* @return - confirmation
	*
	*/
    public function updateBalance($images, $cost) {

        $user = User::find(Auth::user()->id);

        $total = $images * $cost;

        if (auth()->user()->image_credits != -1) {
    
            if (Auth::user()->image_credits > $total) {

                $total_dalle_images = Auth::user()->image_credits - $total;
                $user->image_credits = ($total_dalle_images < 0) ? 0 : $total_dalle_images;

            } elseif (Auth::user()->image_credits_prepaid > $total) {

                $total_dalle_images_prepaid = Auth::user()->image_credits_prepaid - $total;
                $user->image_credits_prepaid = ($total_dalle_images_prepaid < 0) ? 0 : $total_dalle_images_prepaid;

            } elseif ((Auth::user()->image_credits + Auth::user()->image_credits_prepaid) == $total) {

                $user->image_credits = 0;
                $user->image_credits_prepaid = 0;

            } else {

                // if (!is_null(Auth::user()->member_of)) {

                //     $member = User::where('id', Auth::user()->member_of)->first();

                //     if ($member->image_credits > $images) {

                //         $total_dalle_images = $member->image_credits - $images;
                //         $member->image_credits = ($total_dalle_images < 0) ? 0 : $total_dalle_images;
            
                //     } elseif ($member->image_credits_prepaid > $images) {
            
                //         $total_dalle_images_prepaid = $member->image_credits_prepaid - $images;
                //         $member->image_credits_prepaid = ($total_dalle_images_prepaid < 0) ? 0 : $total_dalle_images_prepaid;
            
                //     } elseif (($member->image_credits + $member->image_credits_prepaid) == $images) {
            
                //         $member->image_credits = 0;
                //         $member->image_credits_prepaid = 0;
            
                //     } else {
                //         $remaining = $images - $member->image_credits;
                //         $member->image_credits = 0;
        
                //         $prepaid_left = $member->image_credits_prepaid - $remaining;
                //         $member->image_credits_prepaid = ($prepaid_left < 0) ? 0 : $prepaid_left;
                //     }

                //     $member->update();

                // } else {
                //     $remaining = $images - Auth::user()->image_credits;
                //     $user->image_credits = 0;

                //     $prepaid_left = Auth::user()->image_credits_prepaid - $remaining;
                //     $user->image_credits_prepaid = ($prepaid_left < 0) ? 0 : $prepaid_left;
                // }
            }
        }

        $user->update(); 

    }


     /**
	*
	* Process media file
	* @param - file id in DB
	* @return - confirmation
	*
	*/
	public function view(Request $request) 
    {
        if ($request->ajax()) {

            $image = Image::where('id', request('id'))->first(); 

            if ($image) {
                if ($image->user_id == Auth::user()->id){

                    $image_url = ($image->storage == 'local') ? URL::asset($image->image) : $image->image;
                    $image_vendor = '';
                    switch ($image->vendor) {
                        case 'openai': $image_vendor = 'OpenAI'; break;
                        case 'sd': $image_vendor = 'Stable Diffusion'; break;
                        case 'falai': $image_vendor = 'Fal AI'; break;
                    }
                    $image_url_second = url($image->image);
                    $image_style = ($image->image_style == 'none') ? __('Not Set') : ucfirst($image->image_style);
                    $image_lighting = ($image->image_lighting == 'none') ? __('Not Set') : ucfirst($image->image_lighting);
                    $image_medium = ($image->image_medium == 'none') ? __('Not Set') : ucfirst($image->image_medium);
                    $image_mood = ($image->image_mood == 'none') ? __('Not Set') : ucfirst($image->image_mood);
                    $image_artist = ($image->image_artist == 'none') ? __('Not Set') : ucfirst($image->image_artist);

                    if (!is_null($image->negative_prompt)) {
                        $image_negative_prompt = ' <div class="row mt-5">
                                                    <div class="col-sm-12">
                                                        <h6 class="mb-3 description-title">'. __('Negative Prompt') .'</h6>
                                                        <a href="#" class="copy-image-negative-prompt"><svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 96 960 960" fill="currentColor" width="20"> <path d="M180 975q-24 0-42-18t-18-42V312h60v603h474v60H180Zm120-120q-24 0-42-18t-18-42V235q0-24 18-42t42-18h440q24 0 42 18t18 42v560q0 24-18 42t-42 18H300Zm0-60h440V235H300v560Zm0 0V235v560Z"></path> </svg></a>
                                                        <div class="image-prompt" id="image-negative-prompt-text">
                                                            <p>'. $image->negative_prompt.'</p>
                                                        </div>
                                                    </div>
                                                </div>';
                    } else {
                        $image_negative_prompt = '';
                    }

                    if (is_null($image->vendor_engine)) {
                        $image_engine = __('Not Set');
                    } else {
                        switch ($image->vendor_engine) {
                            case 'dall-e-2': $image_engine = 'Dalle 2'; break;
                            case 'dall-e-3': $image_engine = 'Dalle 3'; break;
                            case 'dall-e-3-hd': $image_engine = 'Dalle 3 HD'; break;
                            case 'stable-diffusion-v1-6': $image_engine = 'Stable Diffusion v1.6'; break;
                            case 'stable-diffusion-xl-1024-v1-0': $image_engine = 'SDXL v1.0'; break;
                            case 'sd3-medium': $image_engine = 'SD 3.0 Medium'; break; 		
                            case 'sd3-large': $image_engine = 'SD 3.0 Large'; break;
                            case 'sd3-large-turbo': $image_engine = 'SD 3.0 Large Turbo'; break;		
                            case 'core': $image_engine = 'Stable Image Core'; break;
                            case 'ultra': $image_engine = 'Stable Image Ultra'; break;
                            case 'flux/dev': $image_engine = 'FLUX.1 [dev]'; break;
                            case 'flux/schnell': $image_engine = 'FLUX.1 [schnell]'; break;																															
                            case 'flux-pro/new': $image_engine = 'FLUX.1 [pro]'; break;																															
                            case 'flux-realism': $image_engine = 'FLUX Realism'; break;
                            default: $image_engine = 'Dalle 2';
                        }
                    }

                    $data['status'] = 'success';
                    $data['modal'] = '<div class="row">
                                        <div class="col-lg-6 col-md-6 col-sm-12 image-view-outer">
                                            <div class="image-view-box text-center">
                                                <a href="'. $image_url_second .'" class="download-image text-center" download><i class="fa-sharp fa-solid fa-arrow-down-to-line" title="' .__('Download Image') .'"></i></a>
                                                <img src="'. $image_url .'" alt="">
                                            </div>
                                        </div>
                                        <div class="col-lg-6 col-md-6 col-sm-12">
                                            <div class="image-description-box">
                                                <div class="row">
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Created')
                                                        .'</div>
                                                        <div class="description-data">
                                                            ' . date_format($image->created_at, 'F d, Y') . '
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('AI Vendor')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image_vendor
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('AI Vendor Engine')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image_engine
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Resolution')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image->resolution
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Image Style')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image_style
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Lighting Style')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image_lighting
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Image Medium')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image_medium
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Artist Name')
                                                       .'</div>
                                                        <div class="description-data">'.
                                                            $image_artist
                                                        .'</div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-6 mb-5">
                                                        <div class="description-title">'.
                                                             __('Image Mood')
                                                        .'</div>
                                                        <div class="description-data">'.
                                                            $image_mood
                                                        .'</div>
                                                    </div>
                                                </div>
                                                <div class="row mt-5">
                                                    <div class="col-sm-12">
                                                        <h6 class="mb-3 description-title">'. __('Image Prompt') .'</h6>
                                                        <a href="#" class="copy-image-prompt"><svg xmlns="http://www.w3.org/2000/svg" height="20" viewBox="0 96 960 960" fill="currentColor" width="20"> <path d="M180 975q-24 0-42-18t-18-42V312h60v603h474v60H180Zm120-120q-24 0-42-18t-18-42V235q0-24 18-42t42-18h440q24 0 42 18t18 42v560q0 24-18 42t-42 18H300Zm0-60h440V235H300v560Zm0 0V235v560Z"></path> </svg></a>
                                                        <div class="image-prompt" id="image-prompt-text">
                                                            <p>'. $image->description.'</p>
                                                        </div>
                                                    </div>
                                                </div>'
                                                . $image_negative_prompt.
                                            '</div>
                                        </div>
                                    </div>';
                    return $data;  
        
                } else{
    
                    $data['status'] = 'error';
                    $data['message'] = __('There was an error while retrieving this image');
                    return $data;
                }  
            } else {
                $data['status'] = 'error';
                $data['message'] = __('Image was not found');
                return $data;
            }
            
        }
	}


    /**
	*
	* Delete File
	* @param - file id in DB
	* @return - confirmation
	*
	*/
	public function delete(Request $request) 
    {
        if ($request->ajax()) {

            $verify = $this->user->verify_license();
            if($verify['status']!=true){return false;}

            $image = Image::where('id', request('id'))->first(); 

            if ($image->user_id == auth()->user()->id){

                switch ($image->storage) {
                    case 'local':
                        if (Storage::disk('public')->exists($image->image)) {
                            Storage::disk('public')->delete($image->image);
                        }
                        break;
                    case 'aws':
                        if (Storage::disk('s3')->exists($image->image_name)) {
                            Storage::disk('s3')->delete($image->image_name);
                        }
                        break;
                    case 'r2':
                        if (Storage::disk('r2')->exists($image->image_name)) {
                            Storage::disk('r2')->delete($image->image_name);
                        }
                        break;
                    case 'wasabi':
                        if (Storage::disk('wasabi')->exists($image->image_name)) {
                            Storage::disk('wasabi')->delete($image->image_name);
                        }
                        break;
                    case 'storj':
                        if (Storage::disk('storj')->exists($image->image_name)) {
                            Storage::disk('storj')->delete($image->image_name);
                        }
                        break;
                    case 'gcp':
                        if (Storage::disk('gcs')->exists($image->image_name)) {
                            Storage::disk('gcs')->delete($image->image_name);
                        }
                        break;
                    case 'dropbox':
                        if (Storage::disk('dropbox')->exists($image->image_name)) {
                            Storage::disk('dropbox')->delete($image->image_name);
                        }
                        break;
                    default:
                        # code...
                        break;
                }

                $image->delete();

                $data['status'] = 'success';
                return $data;  
    
            } else{

                $data['status'] = 'error';
                $data['message'] = __('There was an error while deleting the image');
                return $data;
            }  
        }
	}


    /**
	*
	* Load More Images
	* @param - file id in DB
	* @return - confirmation
	*
	*/
	public function loadMore(Request $request) 
    {
        $start = $request->start;
 
        $images = Image::where('user_id', Auth::user()->id)->latest()->skip($start)->limit(6)->get();
 
        $html = "";
        foreach($images as $image){

            $image_url = ($image->storage == 'local') ? URL::asset($image->image) : $image->image;
            $image_vendor = '';
            switch ($image->vendor) {
                case 'openai': $image_vendor = 'OpenAI'; break;
                case 'sd': $image_vendor = 'Stable Diffusion'; break;
                case 'falai': $image_vendor = 'Fal AI'; break;
            }
            $image_description = substr($image->description, 0, 63);
            $image_url_second = url($image->image);
            $image_id = $image->id;
            
            $html .= '<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 image-container">				
					    <div class="grid-item">
						    <div class="grid-image-wrapper">
                                <div class="flex grid-buttons text-center">
                                    <a href="'. $image_url_second .'" class="grid-image-view text-center" download><i class="fa-sharp fa-solid fa-arrow-down-to-line" title="'. __("Download Image") .'"></i></a>
                                    <a href="#" class="grid-image-view text-center viewImageResult" id="'. $image_id .'"><i class="fa-sharp fa-solid fa-camera-viewfinder" title="'. __("View Image") .'"></i></a>
                                    <a href="#" class="grid-image-view text-center deleteResultButton" id="'. $image_id .'"><i class="fa-solid fa-trash-xmark" title="'. __("Delete Image") .'"></i></a>							
                                </div>
                                <a href="#">
                                    <span class="grid-image">
                                        <img class="loaded" src="' . $image_url . '" alt="" >
                                    </span>
                                </a>
                                <div class="grid-description">
                                    <span class="fs-9 text-primary">' . $image_vendor . '</span>
                                    <p class="fs-10 mb-0">' . $image_description . '...</p>
                                </div>
						    </div>
                        </div>
					</div>';
        }
 
        $data['html'] = $html;

        return response()->json($data);
	}


    public function createImageBox($image) 
    {
        $image_url = ($image->storage == 'local') ? URL::asset($image->image) : $image->image;
        $image_vendor = ($image->vendor == 'sd') ? __('Stable Diffusion') : __('Dalle');
        $image_description = substr($image->description, 0, 63);
        $image_url = url($image->image);
        $image_id = $image->id;

        return '<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 image-container">				
                    <div class="grid-item">
                        <div class="grid-image-wrapper">
                            <div class="flex grid-buttons text-center">
                                <a href="'. $image_url .'" class="grid-image-view text-center" download><i class="fa-sharp fa-solid fa-arrow-down-to-line" title="'. __("Download Image") .'"></i></a>
                                <a href="#" class="grid-image-view text-center viewImageResult" id="'. $image_id .'"e><i class="fa-sharp fa-solid fa-camera-viewfinder" title="'. __("View Image") .'"></i></a>
                                <a href="#" class="grid-image-view text-center deleteResultButton" id="'. $image_id .'"><i class="fa-solid fa-trash-xmark" title="'. __("Delete Image") .'"></i></a>							
                            </div>
                            <a href="#">
                                <span class="grid-image">
                                    <img class="loaded" src="' . $image_url . '" alt="" >
                                </span>
                            </a>
                            <div class="grid-description">
                                <span class="fs-9 text-primary">' . $image_vendor . '</span>
                                <p class="fs-10 mb-0">' . $image_description . '...</p>
                            </div>
                        </div>
                    </div>
                </div>';
    }


    public function build_post_fields( $data,$existingKeys='',&$returnArray=[])
    {
        if(($data instanceof \CURLFile) or !(is_array($data) or is_object($data))){
            $returnArray[$existingKeys]=$data;
            return $returnArray;
        }
        else{
            foreach ($data as $key => $item) {
                $this->build_post_fields($item,$existingKeys?$existingKeys."[$key]":$key,$returnArray);
            }
            return $returnArray;
        }
    }
    

    public function check_credits($model) {
        $credits = ImageCredit::first();

        switch ($model) {
            case 'dall-e-2': return $credits->openai_dalle_2; break;
            case 'dall-e-3': return $credits->openai_dalle_3; break;
            case 'dall-e-3-hd': return $credits->openai_dalle_3_hd; break;
            case 'stable-diffusion-v1-6': return $credits->sd_v16; break;
            case 'stable-diffusion-xl-1024-v1-0': return $credits->sd_xl_v10; break;
            case 'sd3-medium': return $credits->sd_3_medium; break; 		
            case 'sd3-large': return $credits->sd_3_large; break;
            case 'sd3-large-turbo': return $credits->sd_3_large_turbo; break;	
            case 'core': return $credits->sd_core; break;
            case 'ultra': return $credits->sd_ultra; break;
            case 'flux/dev': return $credits->flux_dev; break;
            case 'flux/schnell': return $credits->flux_schnell; break;																															
            case 'flux-pro/new': return $credits->flux_pro; break;																															
            case 'flux-realism': return $credits->flux_realism; break;
            default: return 1;
        }
    }

}