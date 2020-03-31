<?php


namespace app\mobile\controller;


use app\service\FinanceService;
use app\service\PromotionService;
use app\service\UserService;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\View;

class Users extends BaseUc
{
    protected $userService;
    protected $financeService;
    protected $promotionService;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->userService = app('userService');
        $this->financeService = app('financeService');
        $this->promotionService = app('promotionService');
    }

    public function ucenter()
    {
        $balance = cache('balance:' . $this->uid); //当前用户余额
        if (!$balance) {
            $balance = $this->financeService->getBalance($this->uid);
            cache('balance:' . $this->uid, $balance, '', 'pay');
        }
        try {
            $time = $this->user->vip_expire_time - time();
            $day = 0;
            if ($time > 0) {
                $day = ceil(($this->user->vip_expire_time - time()) / (60 * 60 * 24));
            }
            cookie('xwx_vip_expire_time', $this->user->vip_expire_time); //在session里更新用户vip过期时间
            View::assign([
                'balance' => $balance,
                'day' => $day,
                'xwx_vip_expire_time' => $this->user->vip_expire_time,
                'promotion_url' =>  config('site.schema').config('site.mobile_domain').'?pid='.cookie('xwx_user_id'),
                'header' => '用户中心'
            ]);
            return view($this->tpl);
        } catch (DataNotFoundException $e) {
            abort(404, $e->getMessage());
        } catch (ModelNotFoundException $e) {
            abort(404, $e->getMessage());
        }
    }

    public function userinfo()
    {
        if (request()->isPost()) {
            $nick_name = input('nick_name');
            $email = input('email');
            try {
                $this->user->nick_name = $nick_name;
                $this->user->email = $email;
                $result = $this->user->save();
                if ($result) {
                    cookie('xwx_nick_name', $nick_name);
                    return json(['msg' => '修改成功', 'err' => 0]);
                } else {
                    return json(['msg' => '修改失败', 'err' => 1]);
                }
            } catch (DataNotFoundException $e) {
                return json(['msg' => '用户不存在', 'err' => 1]);
            } catch (ModelNotFoundException $e) {
                return json(['msg' => '用户不存在', 'err' => 1]);
            }
        } else {
            View::assign([
                'header' => '用户中心'
            ]);
        }
        return view($this->tpl);
    }

    public function bookshelf()
    {
        $data = $this->userService->getFavors($this->uid, $this->end_point);
        unset($data['page']['query']['page']);
        $param = '';
        foreach ($data['page']['query'] as $k => $v) {
            $param .= '&' . $k . '=' . $v;
        }
        View::assign([
            'books' => $data['books'],
            'page' => $data['page'],
            'param' => $param,
            'header' => '我的书架'
        ]);
        return view($this->tpl);
    }

    public function delfavors()
    {
        $id = explode(',', input('id')); //书籍id;
        $this->userService->delFavors($this->uid, $id);
        return json(['err' => 0, 'msg' => '删除收藏']);
    }

    public function history()
    {
        View::assign([
            'header' => '阅读历史'
        ]);
        return view($this->tpl);
    }
}