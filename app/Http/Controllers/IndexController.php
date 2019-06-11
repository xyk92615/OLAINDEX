<?php

namespace App\Http\Controllers;

use App\Utils\Extension;
use Cache;
use Session;
use Auth;
use ErrorException;
use App\Service\OneDrive;
use App\Utils\Tool;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * OneDriveGraph 索引
 * Class IndexController
 *
 * @package App\Http\Controllers
 */
class IndexController extends Controller
{

    /**
     * 缓存超时时间(秒) 建议10分钟以下，否则会导致资源失效
     *
     * @var int|mixed|string
     */
    public $expires = 1800;

    /**
     * 根目录
     *
     * @var mixed|string
     */
    public $root = '/';

    /**
     * 展示文件数组
     *
     * @var array
     */
    public $show = [];

    /**
     * IndexController constructor.
     */
    public function __construct()
    {
        $this->middleware(['verify.installation', 'verify.token', 'handle.forbid',]);
//        $this->middleware('handle.encrypt')->only(setting('encrypt_option', ['list']));
        $this->middleware('hotlink.protection')->only(['show', 'download', 'thumb', 'thumbCrop']);
        $this->middleware('throttle:10,2')->only(['search', 'searchShow']);


        $this->expires = setting('expires', 1800);
        $this->root = setting('root', '/');
        $this->show = [
            'stream' => explode(' ', setting('stream')),
            'image' => explode(' ', setting('image')),
            'video' => explode(' ', setting('video')),
            'dash' => explode(' ', setting('dash')),
            'audio' => explode(' ', setting('audio')),
            'code' => explode(' ', setting('code')),
            'doc' => explode(' ', setting('doc')),
        ];
    }

    /**
     * @param Request $request
     *
     * @return Factory|RedirectResponse|View
     * @throws ErrorException
     */
    public function home(Request $request)
    {
        return $this->list($request);
    }


    /**
     * @param Request $request
     *
     * @return Factory|RedirectResponse|View
     * @throws ErrorException
     */
    public function list(Request $request)
    {
        // 处理路径
        $requestPath = $request->route()->parameter('query', '/');
        $graphPath = Tool::getOriginPath($requestPath);
        $queryPath = trim(Tool::getAbsolutePath($requestPath), '/');
        $originPath = rawurldecode($queryPath);
        $pathArray = $originPath ? explode('/', $originPath) : [];
        $pathKey = 'one:path:' . $graphPath;
        if (Cache::has($pathKey)) {
            $item = Cache::get($pathKey);
        } else {
            $response = OneDrive::getInstance(one_account())->getItemByPath($graphPath);
            if ($response['errno'] === 0) {
                $item = $response['data'];
                Cache::put($pathKey, $item, $this->expires);
            } else {
                Tool::showMessage($response['msg'], false);

                return view(config('olaindex.theme') . 'message');
            }
        }
        if (Arr::has($item, '@microsoft.graph.downloadUrl')) {
            return redirect()->away($item['@microsoft.graph.downloadUrl']);
        }
        // 获取列表
        $key = 'one:list:' . $graphPath;
        if (Cache::has($key)) {
            $originItems = Cache::get($key);
        } else {
            $response = OneDrive::getInstance(one_account())->getItemListByPath(
                $graphPath,
                '?select=id,eTag,name,size,lastModifiedDateTime,file,image,folder,@microsoft.graph.downloadUrl'
                . '&expand=thumbnails'
            );

            if ($response['errno'] === 0) {
                $originItems = $response['data'];
                Cache::put($key, $originItems, $this->expires);
            } else {
                Tool::showMessage($response['msg'], false);

                return view(config('olaindex.theme') . 'message');
            }
        }
        // 处理排序
        $order = $request->get('orderBy');
        @list($field, $sortBy) = explode(',', $order);
        $itemsBase = collect($originItems);
        if (strtolower($sortBy) !== 'desc') {
            $originItems = $itemsBase->sortBy($field)->toArray();
        } else {
            $originItems = $itemsBase->sortByDesc($field)->toArray();
        }
        // 文件夹排序
        $originItems = collect($originItems)->sortByDesc(static function ($item) {
            if (!isset($item['folder'])) {
                $children = -1;
            } else {
                $children = $item['folder']['childCount'];
            }
            return $children;
        })->toArray();
        $hasImage = Tool::hasImages($originItems);

        // 过滤微软OneNote文件
        $originItems = Arr::where($originItems, static function ($value) {
            return !Arr::has($value, 'package.type');
        });

        // 处理 head/readme
        $head = array_key_exists('HEAD.md', $originItems)
            ? Tool::markdown2Html(Tool::getFileContent($originItems['HEAD.md']['@microsoft.graph.downloadUrl']))
            : '';
        $readme = array_key_exists('README.md', $originItems)
            ? Tool::markdown2Html(Tool::getFileContent($originItems['README.md']['@microsoft.graph.downloadUrl']))
            : '';
        if (!Auth::guest()) {
            $originItems = Arr::except(
                $originItems,
                ['README.md', 'HEAD.md', '.password', '.deny']
            );
        }
        $limit = $request->get('limit', 20);
        $items = Tool::paginate($originItems, $limit);
        $parent_item = $item;
        $data = compact(
            'parent_item',
            'items',
            'originItems',
            'originPath',
            'pathArray',
            'head',
            'readme',
            'hasImage'
        );

        return view(config('olaindex.theme') . 'one', $data);
    }

    /**
     * 获取文件信息或缓存
     *
     * @param $realPath
     *
     * @return mixed
     */
    public function getFileOrCache($realPath)
    {
        $absolutePath = Tool::getAbsolutePath($realPath);
        $absolutePathArr = explode('/', $absolutePath);
        $absolutePathArr = Arr::where($absolutePathArr, static function ($value) {
            return $value !== '';
        });
        $name = array_pop($absolutePathArr);
        $absolutePath = implode('/', $absolutePathArr);
        $listPath = Tool::getOriginPath($absolutePath);
        $list = Cache::get('one:list:' . $listPath, '');
        if ($list && array_key_exists($name, $list)) {
            return $list[$name];
        }
        $graphPath = Tool::getOriginPath($realPath);

        // 获取文件
        return Cache::remember(
            'one:file:' . $graphPath,
            $this->expires,
            static function () use ($graphPath) {
                $response = OneDrive::getInstance(one_account())->getItemByPath(
                    $graphPath,
                    '?select=id,eTag,name,size,lastModifiedDateTime,file,image,@microsoft.graph.downloadUrl'
                    . '&expand=thumbnails'
                );
                if ($response['errno'] === 0) {
                    return $response['data'];
                }
                return null;
            }
        );
    }

    /**
     * 展示文件
     * @param Request $request
     *
     * @return Factory|RedirectResponse|View
     * @throws ErrorException
     */
    public function show(Request $request)
    {
        $requestPath = $request->route()->parameter('query', '/');
        if ($requestPath === '/') {
            return redirect()->route('home');
        }
        $file = $this->getFileOrCache($requestPath);
        if ($file === null || Arr::has($file, 'folder')) {
            Tool::showMessage('获取文件失败，请检查路径或稍后重试', false);

            return view(config('olaindex.theme') . 'message');
        }
        $file['download'] = $file['@microsoft.graph.downloadUrl'];
        foreach ($this->show as $key => $suffix) {
            if (in_array($file['ext'], $suffix, false)) {
                $view = 'show.' . $key;
                // 处理文本文件
                if (in_array($key, ['stream', 'code'])) {
                    if ($file['size'] > 5 * 1024 * 1024) { // 文件>5m
                        Tool::showMessage('文件过大，请下载查看', false);

                        return redirect()->back();
                    }
                    $file['content'] = Tool::getFileContent($file['@microsoft.graph.downloadUrl'], false);
                    if ($key === 'stream') {
                        $fileType
                            = empty(Extension::FILE_STREAM[$file['ext']])
                            ? 'application/octet-stream'
                            : Extension::FILE_STREAM[$file['ext']];

                        return response($file['content'], 200, ['Content-type' => $fileType,]);
                    }
                }
                // 处理缩略图
                if (in_array($key, ['image', 'dash', 'video'])) {
                    $file['thumb'] = Arr::get($file, 'thumbnails.0.large.url');
                }
                // dash视频流
                if ($key === 'dash') {
                    if (!strpos(
                        $file['@microsoft.graph.downloadUrl'],
                        'sharepoint.com'
                    )
                    ) {
                        return redirect()->away($file['download']);
                    }
                    $replace = str_replace('thumbnail', 'videomanifest', $file['thumb']);
                    $file['dash'] = $replace . '&part=index&format=dash&useScf=True&pretranscode=0&transcodeahead=0';
                }
                // 处理微软文档
                if ($key === 'doc') {
                    $url = 'https://view.officeapps.live.com/op/view.aspx?src='
                        . urlencode($file['@microsoft.graph.downloadUrl']);

                    return redirect()->away($url);
                }
                $originPath = rawurldecode(trim(Tool::getAbsolutePath($requestPath), '/'));
                $pathArray = $originPath ? explode('/', $originPath) : [];
                $data = compact('file', 'pathArray', 'originPath');

                return view(config('olaindex.theme') . $view, $data);
            }
            $last = end($this->show);
            if ($last === $suffix) {
                break;
            }
        }

        return redirect()->away($file['download']);
    }

    /**
     * 下载
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function download(Request $request): RedirectResponse
    {
        $requestPath = $request->route()->parameter('query', '/');
        if ($requestPath === '/') {
            Tool::showMessage('下载失败，请检查路径或稍后重试', false);

            return view(config('olaindex.theme') . 'message');
        }
        $file = $this->getFileOrCache($requestPath);
        if ($file === null || Arr::has($file, 'folder')) {
            Tool::showMessage('下载失败，请检查路径或稍后重试', false);

            return view(config('olaindex.theme') . 'message');
        }
        $url = $file['@microsoft.graph.downloadUrl'];

        return redirect()->away($url);
    }

    /**
     * 查看缩略图
     *
     * @param $id
     * @param $size
     *
     * @return RedirectResponse
     * @throws ErrorException
     */
    public function thumb($id, $size): RedirectResponse
    {
        $response = OneDrive::getInstance(one_account())->thumbnails($id, $size);
        if ($response['errno'] === 0) {
            $url = $response['data']['url'];
        } else {
            $url = 'https://i.loli.net/2018/12/04/5c05cd3086425.png';
        }

        return redirect()->away($url);
    }

    /**
     * 指定缩略图
     *
     * @param $id
     * @param $width
     * @param $height
     *
     * @return RedirectResponse
     * @throws ErrorException
     */
    public function thumbCrop($id, $width, $height): RedirectResponse
    {
        $response = OneDrive::getInstance(one_account())->thumbnails($id, 'large');
        if ($response['errno'] === 0) {
            $url = $response['data']['url'];
            @list($url, $tmp) = explode('&width=', $url);
            $url .= strpos($url, '?') ? '&' : '?';
            $thumb = $url . "width={$width}&height={$height}";
        } else {
            $thumb = 'https://i.loli.net/2018/12/04/5c05cd3086425.png';
        }

        return redirect()->away($thumb);
    }

    /**
     * 搜索
     * @param Request $request
     *
     * @return Factory|View
     * @throws ErrorException
     */
    public function search(Request $request)
    {
        $keywords = $request->get('keywords');
        $limit = $request->get('limit', 20);
        if ($keywords) {
            $path = Tool::encodeUrl($this->root);
            $response = OneDrive::getInstance(one_account())->search($path, $keywords);
            if ($response['errno'] === 0) {
                // 过滤结果中的文件夹\过滤微软OneNote文件
                $items = Arr::where($response['data'], static function ($value) {
                    return !Arr::has($value, 'folder') && !Arr::has($value, 'package.type');
                });
            } else {
                Tool::showMessage('搜索失败', false);
                $items = [];
            }
        } else {
            $items = [];
        }
        $items = Tool::paginate($items, $limit);

        return view(config('olaindex.theme') . 'search', compact('items'));
    }

    /**
     * 搜索显示
     * @param $id
     *
     * @return RedirectResponse
     * @throws ErrorException
     */
    public function searchShow($id): RedirectResponse
    {
        $response = OneDrive::getInstance(one_account())->itemIdToPath($id, setting('root'));
        if ($response['errno'] === 0) {
            $originPath = $response['data']['path'];
            if (trim($this->root, '/') !== '') {
                $path = Str::after($originPath, $this->root);
            } else {
                $path = $originPath;
            }
        } else {
            Tool::showMessage('获取连接失败', false);
            $path = '/';
        }

        return redirect()->route('show', $path);
    }

    /**
     * @return Factory|RedirectResponse|View|void
     */
    public function handlePassword()
    {
        $password = request()->get('password');
        $route = decrypt(request()->get('route'));
        $requestPath = decrypt(request()->get('requestPath'));
        $encryptKey = decrypt(request()->get('encryptKey'));
        $data = [
            'password' => encrypt($password),
            'encryptKey' => $encryptKey,
            'expires' => time() + (int)$this->expires * 60, // 目录密码过期时间
        ];
        Session::put('password:' . $encryptKey, $data);

        //todo:处理加密目录
        $arr = $this->handleEncrypt(setting('encrypt_path'));

        $directory_password = $arr[$encryptKey];
        if (strcmp($password, $directory_password) === 0) {
            return redirect()->route($route, Tool::encodeUrl($requestPath));
        }
        Tool::showMessage('密码错误', false);

        return view(
            config('olaindex.theme') . 'password',
            compact('route', 'requestPath', 'encryptKey')
        );
    }


    /*处理加密资源*/
    public function handleEncrypt($itemArray)
    {
    }

    /*处理隐藏资源*/
    public function handleHide($itemArray)
    {
    }

    /*处理禁用资源*/
    public function handleForbid($itemArray)
    {
    }
}
