<?php


namespace app\index\controller;


use app\common\RedisHelper;
use app\model\Author;
use app\model\Book;
use app\model\Chapter;
use app\model\Comments;
use app\model\UserFavor;
use app\service\BookService;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\facade\View;

class Books extends Base
{
    protected $bookService;

    public function initialize()
    {
        parent::initialize();
        $this->bookService = app('bookService');
    }

    public function index()
    {
        $id = input('id');
        $book = cache('book:' . $id);
        if ($book == false) {
            try {
                $book_end_point = config('seo.book_end_point');
                if ($book_end_point == 'id') {
                    $book = Book::with(['chapters' => function ($query) {
                        $query->order('chapter_order');
                    }])->findOrFail($id);
                } else {
                    $book = Book::with(['chapters' => function ($query) {
                        $query->order('chapter_order');
                    }])->where('unique_id', '=', $id)->findOrFail();
                }
            } catch (DataNotFoundException $e) {
                abort(404, $e->getMessage());
            } catch (ModelNotFoundException $e) {
                abort(404, $e->getMessage());
            }
            cache('book:' . $id, $book, null, 'redis');
        }
//        $tags = [];
//        if (!empty($book->tags) || is_null($book->tags)) {
//            $tags = explode('|', $book->tags);
//        }
        $last_chapter = cache('last_chapter:'.$book->id);
        if (!$last_chapter) {
            $query = Db::query('SELECT * FROM '.$this->prefix.
                'chapter WHERE id = (SELECT MAX(id) FROM (SELECT id FROM xwx_chapter WHERE book_id=?) as a)',
                [$book['id']]);
            if (count($query) > 0) {
                $last_chapter = $query[0];
            } else {
                $last_chapter = array();
            }
            cache('last_chapter:'.$book->id, $last_chapter, 'null', 'redis');
        }

//        $chapters = array();
//        $arr = array();
//        $index = 0;
//        foreach ($book['chapters'] as $chapter) {
//            $arr[$index] = $chapter;
//            $index++;
//            if ($index >= 3){
//                array_push($chapters, $arr);
//                $arr = array(); //初始化
//                $index = 0;
//            }
//        }

        $redis = RedisHelper::GetInstance();
        $day = date("Y-m-d", time());
        //以当前日期为键，增加点击数
        $redis->zIncrBy('click:' . $day, 1, $book->id);


        $recommand = cache('randBooks:' . $book->cate_id);
        if (!$recommand) {
            $recommand = $this->bookService->getRecommand($book->cate_id, $this->end_point);
            cache('randBooks:' . $book->tags, $recommand, null, 'redis');
        }

        $param = config('seo.tag_end_point');
        $tags = cache('tags:'.$id);
        if (!$tags) {
            //$tags = Tags::where('id', 'in', $tag->similar)->select();
            $tags = Db::query(
                "select * from " . $this->prefix . "tags where match(tag_name) 
            against ('" . $book->book_name. "') LIMIT 10");
            foreach ($tags as &$t) {
                if ($param == 'id') {
                    $t['param'] = $t['id'];
                } else if ($param == 'pinyin') {
                    $t['param'] = $t['pinyin'];
                } else {
                    $t['param'] = $t['jianpin'];
                }
            }
            cache('tags:'.$id, $tags, null, 'redis');
        }

        $authors = cache('booksForAuthor:'.$book->author_id);
        if (!$authors) {
            $authors = $this->bookService->getByAuthor($book->author_id, $this->end_point);
            cache('booksForAuthor:'.$book->author_id, $authors, null, 'redis');
        }

        $start = cache('bookStart:' . $id);
        if ($start == false) {
            $db = Db::query('SELECT id FROM ' . $this->prefix . 'chapter WHERE book_id = ' . $book->id . ' ORDER BY id LIMIT 1');
            $start = $db ? $db[0]['id'] : -1;
            cache('bookStart:' . $id, $start, null, 'redis');
        }

        $comments = $this->getComments($book->id);

        $isfavor = 0;
        if (!is_null($this->uid)) {
            $where[] = ['user_id', '=', $this->uid];
            $where[] = ['book_id', '=', $book->id];
            try {
                $userfavor = UserFavor::where($where)->findOrFail();
                $isfavor = 1;
            } catch (DataNotFoundException $e) {
            } catch (ModelNotFoundException $e) {
            }
        }

        $start_pay = cache('maxChapterOrder:' . $book->id);
        if (!$start_pay) {
            if ($book->start_pay >= 0) {
                $start_pay = $book->start_pay; //如果是正序，则开始付费章节就是设置的
            } else { //如果是倒序付费设置
                $abs = abs($book->start_pay) - 1; //取得倒序的绝对值，比如-2，则是倒数第2章开始付费
                $max_chapter_order = Db::query("SELECT MAX(chapter_order) as max FROM " . $this->prefix . "chapter WHERE book_id=:id",
                    ['id' => $book->id])[0]['max'];
                cache('maxChapterOrder:' . $id, $max_chapter_order);
                $start_pay = (float)$max_chapter_order - $abs; //计算出起始付费章节
            }
        }

        $clicks = cache('bookClicks:' . $book->id);
        if (!$clicks) {
            $clicks = $this->bookService->getClicks($book->id, $this->prefix);
            cache('bookClicks:' . $book->id, $clicks);
        }
        View::assign([
            'book' => $book,
            'tags' => $tags,
            'start' => $start,
            'authors' => $authors,
            'recommand' => $recommand,
            'isfavor' => $isfavor,
            'comments' => $comments,
            'start_pay' => $start_pay,
            'clicks' => $clicks,
            'last_chapter' => $last_chapter,
        ]);
        return view($this->tpl);
    }

    public function addfavor()
    {
        if (request()->isPost()) {
            if (is_null($this->uid)) {
                return json(['err' => 1, 'msg' => '用户未登录']);
            }
            $redis = RedisHelper::GetInstance();
            if ($redis->exists('favor_lock:' . $this->uid)) { //如果存在锁
                return json(['err' => 1, 'msg' => '操作太频繁']);
            } else {
                $redis->set('favor_lock:' . $this->uid, 1, 3); //写入锁
                $book_id = input('book_id');

                $where[] = ['book_id', '=', $book_id];
                $where[] = ['user_id', '=', $this->uid];
                try {
                    UserFavor::where($where)->findOrFail();
                    return json(['err' => 1, 'msg' => '已加入书架']); //isfavor表示已收藏
                } catch (DataNotFoundException $e) {
                } catch (ModelNotFoundException $e) {
                    $userFaver = new UserFavor();
                    $userFaver->book_id = $book_id;
                    $userFaver->user_id = $this->uid;
                    $userFaver->save();
                    return json(['err' => 0, 'msg' => '成功加入书架']); //isfavor表示已收藏
                }
            }
        }
        return json(['err' => 1, 'msg' => '不是post请求']);
    }

    private function getComments($book_id)
    {
        $comments = cache('comments:' . $book_id);
        if (!$comments) {
            $comments = Comments::with('user')->where('book_id', '=', $book_id)
                ->order('create_time', 'desc')->limit(0, 5)->select();
            cache('comments:' . $book_id, $comments);
        }
        return $comments;
    }

    public function commentadd()
    {
        $book_id = input('book_id');
        $redis = RedisHelper::GetInstance();
        if ($redis->exists('comment_lock:' . $this->uid)) {
            return json(['msg' => '每10秒只能评论一次', 'err' => 1]);
        } else {
            $comment = new Comments();
            $comment->user_id = $this->uid;
            $comment->book_id = $book_id;
            $comment->content = strip_tags(input('comment'));
            $result = $comment->save();
            if ($result) {
                $redis->set('comment_lock:' . $this->uid, 1, 10); //加10秒锁
                cache('comments:' . $book_id, null);
                return json(['msg' => '评论成功', 'err' => 0]);
            } else {
                return json(['msg' => '评论失败', 'err' => 1]);
            }
        }
    }
}