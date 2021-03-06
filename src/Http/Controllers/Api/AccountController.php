<?php
/**
 * Copyright (C) Loopeer, Inc - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential.
 *
 * User: DengYongBin
 * Date: 16/5/16
 * Time: 上午8:24
 */
namespace Loopeer\QuickCms\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Loopeer\Lib\Sms\LuoSiMaoSms;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Loopeer\QuickCms\Services\Utils\QiniuCloud;
use Loopeer\QuickCms\Services\Validators\QuickApiValidator;
use Looopeer\Lib\Sendcloud\SendcloudService;

class AccountController extends BaseController {

    protected $validation;
    protected $model;

    public function __construct(QuickApiValidator $validation) {
        $this->validation = $validation;
        $reflectionClass = new \ReflectionClass(config('quickApi.model_bind.account'));
        $this->model = $reflectionClass->newInstance();
    }

    /**
     * 密码登陆方式
     * @param $request
     * @return mixed
     */
    public function loginByPassword(Request $request) {
        if (!$this->validation->passes($this->validation->loginRules)) {
            return ApiResponse::validation($this->validation);
        }
        // 验证帐号
        if (isset($request->phone)) {
            $query = $this->model->where('phone', $request->phone);
        } else {
            $query = $this->model->where('email', $request->email);
        }
        $account = $query->first();
        if (is_null($account)) {
            return ApiResponse::responseFailure(null, trans('messages.account_not_exist'));
        }
        // 密码错误
        if (md5($request->password) != $account->password) {
            return ApiResponse::responseFailure(null, trans('messages.password_error'));
        }
        // 黑名单
        if (self::isUserForbidden($account)) {
            return ApiResponse::responseFailure(config('quickCms.code.black_account'), trans('messages.black_account'));
        }
        // token
        $token = self::generateToken();
        $account->token = $token;
        $account->last_ip = $request->ip();
        $account->last_time = Carbon::now();
        $account->save();
        return ApiResponse::responseSuccess($account);
    }

    /**
     * 验证码登陆方式
     * @param $request
     * @return mixed
     */
    public function loginByCaptcha(Request $request) {
        if (!$this->validation->passes($this->validation->loginByCaptchaRules)) {
            return ApiResponse::validation($this->validation);
        }
        $phone = $request->input('phone');
        if(self::checkCaptcha($phone, $request->input('captcha'))) {
            return ApiResponse::responseFailure(null, trans('messages.captcha_error'));
        }
        $account = $this->model->where('phone', $phone)->first();
        if(is_null($account)) {
            $account = new $this->model;
            $account->phone = $phone;
            $account->register_platform = $request->header('platform');
            $account->register_channel = $request->header('channel_id');
            $account->register_version = $request->header('build');
        } else {
            if(self::isUserForbidden($account)) {
                return ApiResponse::responseFailure(config('quickCms.code.black_account'), trans('messages.black_account'));
            }
        }
        $account->token = self::generateToken();
        $account->last_ip = $request->ip();
        $account->last_time = Carbon::now();
        $account->save();
        return ApiResponse::responseSuccess($account);
    }

    /**
     * 第三方登录方式
     * @param $request
     * @return mixed
     */
    public function loginByOpen(Request $request)
    {
        if (!$this->validation->passes($this->validation->loginByOpenRules)) {
            return ApiResponse::validation($this->validation);
        }
        $data = $request->all();
        $token = self::generateToken();
        $account = $this->model->where('open_id', $data['open_id'])->first();
        if (isset($account)) {
            $account->token = $token;
            $account->save();
        } else {
            $data['token'] = $token;
            $data['register_platform'] = $request->header('platform');
            $data['register_channel'] = $request->header('channel_id');
            $data['register_version'] = $request->header('build');
            if (isset($data['avatar'])) {
                $qiniu = new QiniuCloud();
                $key = 'image_' . date('YmdHis') . rand(1000, 4000);
                $qiniu->fetch($key, $data['avatar']);
                $data['avatar'] = $key;
            }
            $account = $this->model->create($data);
        }
        return ApiResponse::responseSuccess($account);
    }

    /**
     * 用户注册API
     * @param $request
     * @return mixed
     */
    public function register(Request $request) {
        if (!$this->validation->passes($this->validation->registerRules)) {
            return ApiResponse::validation($this->validation);
        }
        $phone = $request->phone;
        $email = $request->email;
        if (isset($phone)) {
            $query = $this->model->where('phone', $phone);
            $data['phone'] = $phone;
        } else {
            $query = $this->model->where('email', $email);
            $data['email'] = $email;
        }
        $account = $query->first();
        // 已被注册
        if (!is_null($account)) {
            return ApiResponse::responseFailure(null, isset($phone) ? trans('messages.phone_is_register') : trans('messages.email_is_register'));
        }
        // 验证码输入错误
        if (self::checkCaptcha(isset($phone) ? $phone : $email, $request->captcha)) {
            return ApiResponse::responseFailure(null, trans('messages.captcha_error'));
        }
        $token = self::generateToken();

        if (config('quickApi.account_bind_im')) {
            $data['im_username'] = date('YmdHis') . rand(100000, 999999);
            $data['im_password'] = md5(rand(100000, 999999));
        }
        $data['password'] = md5($request->password);
        $data['token'] = $token;
        $data['register_platform'] = $request->header('platform');
        $data['register_channel'] = $request->header('channel_id');
        $data['register_version'] = $request->header('build');

        $account = $this->model->create($data);
        return ApiResponse::responseSuccess($account);
    }

    /**
     * 忘记密码API
     * @param $request
     * @return mixed
     */
    public function forgetPassword(Request $request) {
        if (!$this->validation->passes($this->validation->registerRules)) {
            return ApiResponse::validation($this->validation);
        }
        $phone = $request->phone;
        $email = $request->email;
        if (isset($phone)) {
            $query = $this->model->where('phone', $phone);
        } else {
            $query = $this->model->where('email', $email);
        }
        // 验证码输入错误
        if (self::checkCaptcha(isset($phone) ? $phone : $email, $request->captcha)) {
            return ApiResponse::responseFailure(null, trans('messages.captcha_error'));
        }
        $account = $query->first();
        if ($account == null) {
            return ApiResponse::responseFailure(null, trans('messages.account_not_exist'));
        }
        $account->password = md5($request->password);
        $account->save();
        return ApiResponse::responseSuccess();
    }

    /**
     * 修改密码API
     * @param $request
     * @return mixed
     */
    public function updatePassword(Request $request) {
        if (!$this->validation->passes($this->validation->updatePwdRules)) {
            return ApiResponse::validation($this->validation);
        }
        $account = Auth::user()->get();
        if ($account->password != md5($request->old_password)) {
            return ApiResponse::responseFailure(null, trans('messages.oldPassword_error'));
        }
        $account->password = md5($request->password);
        $account->save();
        return ApiResponse::responseSuccess();
    }

    /**
     * 获取验证码API
     * @param $request
     * @return mixed
     */
    public function captcha(Request $request) {
        if (!$this->validation->passes($this->validation->captchaRules)) {
            return ApiResponse::validation($this->validation);
        }
        $phone = $request->phone;
        $email = $request->email;
        if (config('quickApi.captcha_switch')) {
            $captcha = $phone == env('test_phone') ? '1234' : rand(1000, 9999);
            // 发送短信至用户
            if (isset($phone)) {
                $message = sprintf(config('quickApi.sms.captcha'), $captcha);
                $sms = new LuoSiMaoSms(config('quickApi.sms.api_key'));
                $sms->sendSms($phone, $message);
            } else {
                if(stripos($email, '@qq.com') > 0) {
                    $send = new SendcloudService(config('quickCms.sendcloud_api_key'), config('quickCms.sendcloud_api_users')[0]);
                    $data = [
                        'from' => config('quickApi.mail.account'),
                        'to' => $email,
                        'subject' => config('quickApi.mail.subject'),
                        'html' => view(config('quickApi.mail.view'), compact('captcha', 'email')),
                    ];
                    $send->send($data);
                } else {
                    Mail::send(config('quickApi.mail.view'), ['captcha' => $captcha, 'email' => $email], function ($m) use ($email) {
                        $m->from(config('quickApi.mail.account'), config('quickApi.mail.name'));
                        $m->to($email)->subject(config('quickApi.mail.subject'));
                    });
                }
            }
        } else {
            $captcha = '1234';
        }
        $expiresAt = Carbon::now()->addMinutes(30);
        Cache::put(isset($phone) ? $phone : $email, $captcha, $expiresAt);
        return ApiResponse::responseSuccess();
    }

    /**
     * 校验验证码
     * @param $request
     * @return mixed
     * @throws \Exception
     */
    public function validatorCaptcha(Request $request)
    {
        if (!$this->validation->passes($this->validation->validatorCaptchaRules)) {
            return ApiResponse::validation($this->validation);
        }
        $phone = $request->phone;
        $email = $request->email;
        if (isset($phone)) {
            $query = $this->model->where('phone', $phone);
        } else {
            $query = $this->model->where('email', $email);
        }
        $account = $query->first();
        if (!is_null($account)) {
            return ApiResponse::responseFailure(null, trans('messages.email_is_register'));
        }
        // 验证码输入错误
        if (self::checkCaptcha(isset($phone) ? $phone : $email, $request->captcha)) {
            return ApiResponse::responseFailure(null, trans('messages.captcha_error'));
        }
        return ApiResponse::responseSuccess();
    }

    /**
     * 个人详情
     * @return mixed
     */
    public function detail() {
        $account = Auth::user()->get();
        return ApiResponse::responseSuccess($account);
    }

    /**
     * 验证码是否一致
     * @param string $phone
     * @param string $captcha 验证码
     * @return bool
     */
    private function checkCaptcha($phone, $captcha) {
        if ($phone == config('quickApi.sms.test_phone')) {
            return false;
        }
        $captcha_service = Cache::get($phone);
        if ($captcha != $captcha_service) {
            return true;
        }
        return false;
    }

    /**
     * 用户更新
     * @param $request
     * @return mixed
     */
    public function update(Request $request) {
        $account = Auth::user()->get();
        $account->update($request->all());
        return ApiResponse::responseSuccess($account);
    }
    
    /**
     * 获取语音验证码API
     * @param $request
     * @return mixed
     */
    public function verify(Request $request) {
        if (!$this->validation->passes($this->validation->phoneRules)) {
            return ApiResponse::validation($this->validation);
        }
        $phone = $request->phone;
        if(config('quickApi.captcha_switch')) {
            $captcha = rand(1000, 9999);
            $sms = new LuoSiMaoSms(config('quickApi.sms.api_key_verify'));
            // 拨打语音电话至用户
            $sms->sendVerify($phone, $captcha);
        } else {
            $captcha = '1234';
        }
        $expiresAt = Carbon::now()->addMinutes(30);
        Cache::put($phone, $captcha, $expiresAt);
        return ApiResponse::responseSuccess();
    }

    /**
     * 根据username查询用户详情
     * @param $request
     * @return mixed
     */
    public function easeMobDetail(Request $request) {
        $account = $this->model->where('im_username', $request->im_username)->first();
        return ApiResponse::responseSuccess($account);
    }

    /**
     * 用户xx业务记录分页
     * @param $type
     * @return mixed
     * @throws \Exception
     */
    public function myListByPage($type)
    {
        $pageSize = $this->setCurrentPage();
        $account = Auth::user()->get();
        $myList = $account->{str_plural($type)}()->latest()->simplePaginate($pageSize);
        return ApiResponse::responseSuccessWithSimplePagination($myList);
    }

    public function myListByOffset($type, Request $request)
    {
        $offset = $request->input('offset', 0);
        $pageSize = $request->input('page_size', 30);
        $account = Auth::user()->get();
        $totalSize = $account->{str_plural($type)}()->count();
        $myList = $account->{str_plural($type)}()->latest()->skip($offset)->take($pageSize)->get();
        return ApiResponse::responseSuccessWithOffset($myList, $offset, $totalSize, $pageSize);
    }

    /**
     * 用户xx业务记录
     * @param $type
     * @return mixed
     * @throws \Exception
     */
    public function myList($type)
    {
        $account = Auth::user()->get();
        $myList = $account->{str_plural($type)}()->latest()->get();
        return ApiResponse::responseSuccess($myList);
    }
}

