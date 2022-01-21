<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Annotation\Middleware;
use App\Middleware\JWTAuthMiddleware;
use App\Service\SplitUploadService;
use League\Flysystem\Filesystem;
use Psr\Http\Message\ResponseInterface;

/**
 * 上传文件控制器
 * @Controller(prefix="/api/v1/upload")
 * @Middleware(JWTAuthMiddleware::class)
 *
 * @package App\Controller\Api\V1
 */
class UploadController extends CController
{
    /**
     * @inject
     * @var SplitUploadService
     */
    private $splitUploadService;

    /**
     * 图片文件流上传接口
     *
     * @RequestMapping(path="avatar", methods="post")
     * @param Filesystem $filesystem
     * @return ResponseInterface
     */
    public function fileStream(Filesystem $filesystem): ResponseInterface
    {
        $file = $this->request->file("file");

        if (!$file->isValid()) {
            return $this->response->fail();
        }

        $path = 'public/media/images/avatar/' . date('Ymd') . '/' . create_random_filename('png');
        try {
            $filesystem->write($path, file_get_contents($file->getRealPath()));
        } catch (\Exception $e) {
            return $this->response->fail();
        }

        return $this->response->success(['avatar' => get_media_url($path)]);
    }

    /**
     * 获取拆分文件信息
     *
     * @RequestMapping(path="multipart/initiate", methods="post")
     */
    public function initiateMultipart(): ResponseInterface
    {
        $params = $this->request->inputs(['file_name', 'file_size']);
        $this->validate($params, [
            'file_name' => "required",
            'file_size' => 'required|integer'
        ]);

        $data = $this->splitUploadService->create($this->uid(), $params['file_name'], $params['file_size']);

        $data['hash_name'] = $data["upload_id"];

        return $data ? $this->response->success($data) : $this->response->fail('获取文件拆分信息失败！');
    }

    /**
     * 文件拆分上传接口
     *
     * @RequestMapping(path="multipart", methods="post")
     */
    public function fileSubareaUpload(): ResponseInterface
    {
        $file   = $this->request->file('file');
        $params = $this->request->inputs(['upload_id', 'split_index', 'split_num']);
        $this->validate($params, [
            'upload_id'   => 'required',
            'split_index' => 'required',
            'split_num'   => 'required'
        ]);

        if (!$file || !$file->isValid()) {
            return $this->response->fail();
        }

        $user_id   = $this->uid();
        $uploadRes = $this->splitUploadService->upload($user_id, $file, $params['upload_id'], intval($params['split_index']));
        if (!$uploadRes) {
            return $this->response->fail('上传文件失败！');
        }

        if (($params['split_index'] + 1) == $params['split_num']) {
            $fileInfo = $this->splitUploadService->merge($user_id, $params['upload_id']);
            if (!$fileInfo) {
                return $this->response->fail('上传文件失败！');
            }

            return $this->response->success([
                'is_file_merge' => true,
                'hash' => $params['upload_id']
            ]);
        }

        return $this->response->success(['is_file_merge' => false]);
    }
}
