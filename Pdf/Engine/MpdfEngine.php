<?php
/**
 * MpdfEngine.php
 *
 * @author  Daniel Sturm
 * @author  Alexander Rauser
 * @build   2017-07-18
 */

App::uses('AbstractPdfEngine', 'CakePdf.Pdf/Engine');
App::uses('Multibyte', 'I18n');

/**
 * class MpdfEngine
 *
 * extends AbstractPdfEngine
 */
class MpdfEngine extends AbstractPdfEngine
{
    /**
     * [$_host description]
     * @var [type]
     */
    protected $_host;

    /**
     * Constructor
     *
     * @param $Pdf CakePdf instance
     */
    public function __construct(CakePdf $Pdf)
    {
        parent::__construct($Pdf);
        App::import('Vendor', 'CakePdf.Mpdf', ['file' => 'mpdf' . DS . 'mpdf.php']);
        $this->_host = preg_replace('@^https?:\/\/@iU', '', FULL_BASE_URL);

        if (!Cache::config('assets')) {
            $_base_config = Cache::config('course_pdf');
            if ($_base_config) {
                unset($_base_config['settings']['config_name']);
                if (!isset($_base_config['settings']['groups']) ||
                    'default' === $_base_config['settings']['groups'][0]) {
                    Cache::config('course_pdf', array_merge($_base_config['settings'], [
                        'groups' => [
                            'courses',
                        ],
                    ]));
                }
            }
            $config = Hash::merge($_base_config ? $_base_config['settings'] : [
                'duration' => 0 < Configure::read('debug') ? '+1 hour' : '+1 day',
                'engine' => 'File',
            ], [
                'path' => CACHE . 'assets' . DS,
                'prefix' => 'assets_' . (0 < Configure::read('debug') ? 'debug_' : ''),
                'groups' => [
                    'queries',
                ],
            ]);
            Cache::config('assets', $config);
        }
    }

    /**
     * [__getAssetByCurl description]
     * @method __getAssetByCurl
     * @param [type] $url          [description]
     * @param mixed  $base64Encode
     */
    private function __getAssetByCurl($url, $base64Encode = false)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $cache_key = 'asset_by_curl_' . basename($url) . '_' . md5($url);
        $asset_data = Cache::remember($cache_key, function () use ($url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HEADER => 0,
                CURLOPT_RETURNTRANSFER => 1,
            ]);
            $content = curl_exec($ch);
            $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (404 === $code) {
                return null;
            }
            return compact('content', 'mime');
        }, 'assets');

        if (!$asset_data) {
            return false;
        }
        extract($asset_data);

        if ($base64Encode) {
            return $this->__base64Encode($content, $mime);
        }

        return $content;
    }

    /**
     * [__base64Encode description]
     * @method __base64Encode
     * @param  [type]     $uri     [description]
     * @param  mixed      $content
     * @param  null|mixed $mime
     * @return [type]     [description]
     */
    private function __base64Encode($content, $mime = null)
    {
        $encode = true;
        if (is_null($mime) ||
            !$mime) {
            $firstBytes = substr(trim($content), 0, 64);
            switch (true) {
                case false !== strpos($firstBytes, '<svg '):
                    $mime = 'image/svg+xml;utf-8';
                    break;
                case false !== strpos($firstBytes, 'PNG'):
                    $mime = 'image/png';
                    break;
                case false !== strpos($firstBytes, 'JFIF'):
                    $mime = 'image/jpg';
                    break;
                default:
                    return false;
            }
        }
        return sprintf('data:%s;base64,%s', $mime, $encode ? base64_encode($content) : $content);
    }

    /**
     * [_inlineStylesheets description]
     * @method _inlineStylesheets
     * @param  [type] $content [description]
     * @return [type] [description]
     */
    protected function _inlineStylesheets($content)
    {
        $regexp = '/<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^>"\']*)["\'].*?>/si';
        if (!preg_match_all($regexp, $content, $m, PREG_SET_ORDER)) {
            return $content;
        }
        for ($i = 0, $l = count($m); $i < $l; $i++) {
            $asset = $m[$i];
            if (false === strpos($asset[1], $this->_host)) {
                continue;
            }
            $assetContent = $this->__getAssetByCurl($asset[1]);
            if ($assetContent) {
                $content = str_replace($asset[0], '<style>' . $assetContent . '</style>', $content);
            }
            unset($assetContent);
        }
        return $content;
    }

    /**
     * [_inlineImages description]
     * @method _inlineImages
     * @param  [type] $content [description]
     * @return [type] [description]
     */
    protected function _inlineImages($content)
    {
        $regexp = '/<img[^>]*src=["\']([^>"\']*)["\'].*?>/si';
        if (!preg_match_all($regexp, $content, $m, PREG_SET_ORDER)) {
            return $content;
        }
        for ($i = 0, $l = count($m); $i < $l; $i++) {
            $asset = $m[$i];
            if (false === strpos($asset[1], $this->_host)) {
                continue;
            }
            $assetContent = $this->__getAssetByCurl($asset[1], true);
            if ($assetContent) {
                $content = str_replace('src="' . $asset[1] . '"', 'src="' . $assetContent . '"', $content);
            }
        }
        return $content;
    }

    /**
     * [_replaceAssetUrls description]
     * @method _replaceAssetUrls
     * @param [type] $content [description]
     */
    protected function _replaceAssetUrls($content)
    {
        // Rewrite internal ip asset urls
        $internal_ip = env('SERVER_ADDR');
        if ($internal_ip/* && in_array($internal_ip, ['10.251.3.68','37.61.205.200','37.61.205.123','37.61.206.113'])*/) {
            // $content = str_replace(Router::url('/', true), 'https://www.ibb.com/', $content);
            $content = str_replace($internal_ip, $this->_host, $content);
            $content = str_replace(WWW_ROOT, FULL_BASE_URL . '/', $content);
            $content = str_replace($this->_host . '/css', $this->_host . '/theme/Ibb/css', $content);
        }
        if (!!Configure::read('Site.forcehttps')) {
            $content = str_replace('http://' . $this->_host, 'https://' . $this->_host, $content);
        }
        return $content;
    }

    /**
     * Generates Pdf from html
     *
     * @method output
     * @return string raw pdf data
     */
    public function output()
    {
        // mPDF often produces a whole bunch of errors, although there is a pdf created when debug = 0
        // Configure::write('debug', 0);

        $content = $this->_Pdf->html();

        Configure::write('Site.forcehttps', true);
        
        $content = $this->_replaceAssetUrls($content);
        $content = $this->_inlineStylesheets($content);
        // $content = $this->_inlineImages($content);
        
        // catch output if debug is true
        if (Configure::read('debug') > 0) {
            echo $content;
            exit;
        }

        // fix utf-8 error
        // https://github.com/osTicket/osTicket/issues/1395#issuecomment-266522612
        $content = mb_convert_encoding(' ' . $content, 'UTF-8', 'UTF-8');

        error_reporting(0);
        $MPDF = new mPDF();
        $MPDF->useSubstitutions = false;
        $MPDF->simpleTables = true;
        $MPDF->SetAutoPageBreak(false);
        $MPDF->writeHTML($content);

        return $MPDF->Output('', 'S');
    }
}

/* end of file MpdfEngine.php */
