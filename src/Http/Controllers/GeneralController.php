<?php
/**
 * Copyright (C) Loopeer, Inc - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential.
 *
 * User: DengYongBin
 * Date: 15/12/15
 * Time: 下午5:54
 */

namespace Loopeer\QuickCms\Http\Controllers;

use Route;
use Session;
use Response;
use Input;
use View;
use Redirect;

class GeneralController extends BaseController
{

    protected $model_class;
    protected $model_name;
    protected $route_name;
    protected $column_name;
    protected $column_rename;
    protected $column;
    protected $edit_column;
    protected $edit_column_name;
    protected $edit_column_detail;
    protected $actions;
    protected $createable;
    protected $model;

    public function __construct() {
        $path = str_replace('admin/', '', Route::getCurrentRoute()->getPath());
        $path = str_replace('/create', '', $path);
        $path = str_replace('/search', '', $path);
        $path = preg_replace('/\/{\w*}/', '', $path);
        $this->route_name = preg_replace('/\/[0-9]+\/edit/', '', $path);
        $general_name = 'general.' . $this->route_name;
        $this->column = config($general_name . '_index_column');
        $this->column_name = config($general_name . '_index_column_name');
        $this->column_rename = config($general_name . '_index_column_rename');
        $this->edit_column = config($general_name . '_edit_column');
        $this->edit_column_name = config($general_name . '_edit_column_name');
        $this->edit_column_detail = config($general_name . '_edit_column_detail');
        $this->model_class = config($general_name . '_model_class');
        $this->model_name = config($general_name . '_model_name');
        $this->actions = config($general_name . '_table_action');
        $this->createable = config($general_name . '_createable');
        $reflectionClass = new \ReflectionClass($this->model_class);
        $this->model = $reflectionClass->newInstance();
    }

    public function search()
    {
        $ret = self::simplePage($this->column, $this->model);
        return Response::json($ret);
    }

    public function index()
    {
        $message = Session::get('message');
        $data = array(
            'column_name' => $this->column_name,
            'column_rename' => $this->column_rename,
            'route_name' => $this->route_name,
            'model_name' => $this->model_name,
            'actions' => $this->actions,
            'createable' => $this->createable,
            'columns' => $this->column,
            'message' => $message
        );
        return View::make('backend::generals.index', $data);
    }

    /**
     * 删除记录
     * @param $id
     * @return int
     */
    public function destroy($id) {
        $model = $this->model;
        $result = $model::destroy($id);
        return $result ? 1 : 0;
    }

    /**
     * 添加记录
     * @return mixed
     */
    public function create() {
        $model_data = $this->model;
        $data = self::getEditData($model_data);
        return View::make('backend::generals.create', $data);
    }

    public function show() {

    }

    /**
     * 保存记录
     * @return mixed
     */
    public function store() {
        $data = Input::all();
        $model = $this->model;
        foreach($data as $key => $value) {
            if(is_array($value)) {
                $data[$key] = implode(',', $value);
            }
        }
        if ($data['id']) {
            $result = $model::find($data['id'])->update($data);
        } else {
            $result = $model::create($data);
        }
        $message['result'] = $result ? true : false;
        $message['content'] = $message['result'] ? '操作成功' : '操作失败';

        return Redirect::to('admin/' . $this->route_name)->with('message', $message);
    }

    /**
     * 编辑记录
     * @param $id
     * @return mixed
     */
    public function edit($id) {
        $model = $this->model;
        $model_data = $model::find($id);
        $data = self::getEditData($model_data);
        return View::make('backend::generals.create', $data);
    }

    private function getEditData($model_data) {
        $image_config = false;
        $images = array();
        foreach ($this->edit_column_detail as $k => $v) {
            if (!isset($v['type'])) {
                continue;
            }
            if ($v['type'] == 'image') {
                $image_config = true;
                $v['name'] = $k;
                $images[] = $v;
            }
        }
        $data = array(
            'route_name' => $this->route_name,
            'model_name' => $this->model_name,
            'edit_column' => $this->edit_column,
            'edit_column_name' => $this->edit_column_name,
            'edit_column_detail' => $this->edit_column_detail,
            'model_data' => $model_data,
            'image_config' => $image_config,
            'images' => $images
        );
        return $data;
    }
}