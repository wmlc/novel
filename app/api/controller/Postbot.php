<?php


namespace app\api\controller;


use app\BaseController;
use app\model\ChapterLogs;
use app\service\ChapterService;
use Overtrue\Pinyin\Pinyin;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use app\model\Photo;
use app\model\BookLogs;
use app\model\Author;
use app\model\Book;
use app\model\Chapter;
use think\facade\App;

class PostBot extends BaseController
{
    protected $chapterService;

    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->chapterService = app('chapterService');
    }

    public function save()
    {
        if (request()->isPost()) {
            $data = request()->param();
            $key = $data['api_key'];
            if (empty($key) || is_null($key)) {
                return 'api密钥不能为空！';
            }
            if ($key != config('site.api_key')) {
                return 'api密钥错误！';
            }

            $book_id = -1;
            $where[] = ['src_url','=',$data['src_url']];
            $where[] = ['src','=',$data['src']];
            $booklog = Booklogs::where($where)->find();
            if (is_null($booklog)) { //如果小说不存在
                $author = Author::where('author_name', '=', trim($data['author']))->find();
                if (is_null($author)) {//如果作者不存在
                    $author = new Author();
                    $author->author_name = $data['author'] ?: '侠名';
                    $author->save();
                }
                $book = new Book();
                $book->author_id = $author->id;
                $book->author_name = $data['author'] ?: '侠名';
                $book->cate_id = trim($data['cate_id']);
                $book->book_name = trim($data['book_name']);
                if (!empty($data['nick_name']) || !is_null($data['nick_name'])) {
                    $book->nick_name = trim($data['nick_name']);
                }
                $book->tags = trim($data['tags']);
                $book->end = trim($data['end']);
                $book->cover_url = trim($data['cover_url']);
                $book->summary = trim($data['summary']);
                $book->last_time = time();
                $str = $this->convert($book->book_name); //生成标识
                if (is_null($str) || empty($str)) {
                    $book->unique_id = md5(time() . mt_rand(1,1000000));
                    sleep(0.1);
                }
                if (Book::where('unique_id','=',$str)->select()->count() > 0) { //如果已经存在相同标识
                    $book->unique_id = md5(time() . mt_rand(1,1000000));
                    sleep(0.1);
                } else {
                    $book->unique_id = $str;
                }
                $book->words = (float)mb_strlen($data['content'])/10000.00;
                $book->save();
                $book_id = $book->id;

                $booklog = new BookLogs();
                $booklog->book_id = $book_id;
                $booklog->src_url = $data['src_url'];
                $booklog->src = $data['src'];
                $result = $booklog->save();
                if(!$result) {
                    return json(['success' => 0, 'msg' => '小说保存失败']);
                }
            }
            else {
                $book_id = $booklog['book_id'];
                try {
                    $book = Book::findOrFail($book_id);
                    $book->update_time = time();
                    $book->words = (float)$book->words +  (float)mb_strlen($data['content'])/10000.00;
                    $book->save();
                } catch (DataNotFoundException $e) {
                    abort(404, '小说不存在，发布出错');
                } catch (ModelNotFoundException $e) {
                    abort(404, '小说不存在，发布出错');
                }

            }
            $this->addChapter($book_id, $data);
        } else {
            return json(['success' => 0, 'msg' => '非法请求']);
        }
    }

    public function addChapter($book_id, $data)
    {
        $chapterlog = ChapterLogs::where('c_src_url','=',$data["c_src_url"])->find();
        if (empty($chapterlog)) {
            $content= iconv("CP1257","UTF-8", $data['content']);
            $dir = App::getRootPath().'/public/static/upload/book/content/'.$book_id.'/';
            if (!file_exists($dir)) {
                mkdir($dir,0777,true);
            }
            $file = md5($data['chapter_name'].time()).'.txt';
            file_put_contents($dir . $file, $content);
            $chapter = new Chapter();
            $chapter->chapter_name = trim($data['chapter_name']);
            $chapter->book_id = $book_id;
            $lastChapterOrder = 0;
            $lastChapter = $this->chapterService->getLastChapter($book_id);
            if ($lastChapter) {
                $lastChapterOrder = $lastChapter->chapter_order;
            }
            $chapter->chapter_order = $lastChapterOrder + 1;
            $chapter->content_url = 'static/upload/book/content/'.$book_id.'/'.$file;
            $chapter->save();
            $chapterlog = new ChapterLogs();
            $chapterlog->chapter_id = $chapter->id;
            $chapterlog->c_src_url = trim($data["c_src_url"]);
            $result = $chapterlog->save();
            if($result) {
                return json(['success' => 1, 'msg' => '发布成功']);
            } else {
                return json(['success' => 0, 'msg' => '章节保存失败']);
            }
        }
    }

    protected function convert($str){
        $pinyin = new Pinyin();
        $name_format = config('seo.name_format');
        switch ($name_format) {
            case 'pure':
                $arr = $pinyin->convert($str);
                $str = implode($arr,'');
                halt($str);
                break;
            case 'abbr':
                $str = $pinyin->abbr($str);break;
            default:
                $str = $pinyin->convert($str);break;
        }
        return $str;
    }
}