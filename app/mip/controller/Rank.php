<?php


namespace app\mip\controller;


use app\service\BookService;
use think\facade\View;

class Rank extends Base
{
    protected $bookService;
    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->bookService = app('bookService');
    }
    public function index() {
        $hot_books = cache('hotBooks');
        if (!$hot_books) {
            $hot_books = $this->bookService->getHotBooks($this->prefix, $this->end_point);
            cache('hotBooks', $hot_books, null, 'redis');
        }

        $newest = cache('newestHomepage');
        if (!$newest) {
            $newest = $this->bookService->getBooks( $this->end_point, 'last_time', '1=1', 10);
            cache('newestHomepage', $newest, null, 'redis');
        }

        $ends = cache('endsHomepage');
        if (!$ends) {
            $ends = $this->bookService->getBooks( $this->end_point, 'last_time', [['end', '=', '2']], 10);
            cache('endsHomepage', $ends, null, 'redis');
        }

        View::assign([
            'newest' => $newest,
            'hot' => $hot_books,
            'ends' => $ends,
            'header' => '排行榜'
        ]);
        return view($this->tpl);
    }
}