<?php
/**
 * MpdfEngine.php
 *
 * @author Daniel Sturm
 * @author Alexander Rauser
 * @build  2017-07-18
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
     *
     * @var [type]
     */
    protected $_host;

    /**
     * Flag wether to check SSL
     *
     * @var boolean
     */
    private $_verifySsl = true;

    /**
     * Use library version.
     *
     * @var string
     */
    private $_version = 'latest';

    /**
     * Contain the MPDF class.
     *
     * @var [type]
     */
    public $mpdf = null;

    /**
     * Config
     *
     * @var array
     */
    public $config = [];

    /**
     * Constructor
     *
     * @param $Pdf CakePdf instance
     */
    public function __construct(CakePdf $Pdf)
    {
        parent::__construct($Pdf);

        $this->_initMPDF();

        $this->_host = preg_replace('@^https?:\/\/@iU', '', FULL_BASE_URL);

        if (!Cache::config('assets')) {
            $_base_config = Cache::config('course_pdf');
            if ($_base_config) {
                unset($_base_config['settings']['config_name']);
                if (!isset($_base_config['settings']['groups'])
                    || 'default' === $_base_config['settings']['groups'][0]
                ) {
                    Cache::config(
                        'course_pdf',
                        array_merge(
                            $_base_config['settings'],
                            [
                                'groups' => [
                                    'courses',
                                ],
                            ]
                        )
                    );
                }
            }
           
            $config = Hash::merge(
                $_base_config ?
                $_base_config['settings'] :
                [
                    'duration' => 0 < Configure::read('debug') ? '+1 hour' : '+1 day',
                    'engine' => 'File',
                ],
                [
                    'path' => CACHE . 'assets' . DS,
                    'prefix' => 'assets_' . (0 < Configure::read('debug') ? 'debug_' : ''),
                    'groups' => [
                        'queries',
                    ],
                ]
            );
            Cache::config('assets', $config);
        }
    }

    /**
     * Init MPDF class
     *
     * @return void
     */
    private function _initMPDF()
    {
        $root = CakePlugin::path('CakePdf');
        
        if (file_exists($root . 'vendor/autoload.php')) {
            include_once $root . 'vendor/autoload.php';
        }

        if (!array_key_exists('__composer_autoload_files', $GLOBALS)
            && $this->_version
            && 'latest' !== $this->_version
        ) {
            App::import(
                'Vendor',
                'CakePdf.Mpdf',
                [
                    'file' => 'mpdf' . ($this->_version ? '-' . $this->_version : '' ) . DS . 'mpdf.php'
                ]
            );
        }

        if ('latest' === $this->_version
            || version_compare($this->_version, '7', '>=')
        ) {
            try {
                $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
                $fontDirs = $defaultConfig['fontDir'];

                $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
                $fontData = $defaultFontConfig['fontdata'];

                $this->config = Hash::merge(
                    [
                        'debug' => Configure::read('debug') > 0,
                        'showImageErrors' => Configure::read('debug') > 0,
                        'curlAllowUnsafeSslRequests' => !! ($this->_verifySsl),
                        'tempDir' => DS . ltrim(TMP . 'cache' . DS . 'mpdf', DS),
                        'fontDir' => array_merge(
                            (array) $fontDirs,
                            [DS . ltrim(CakePlugin::path('CakePdf') . 'Fonts', DS)]
                        ),
                        'fontdata' => $fontData,
                        'use_kwt' => true,
                        // 'autoPageBreak' => true,
                    ],
                    (array)Configure::read('CakePdf')
                );

                $this->mpdf = new \Mpdf\Mpdf($this->config);
            } catch (\Mpdf\MpdfException $e) {
                // Note: safer fully qualified exception name used for catch
                // Process the exception, log, print etc.
                echo $e->getMessage();
                die;
            }
        } else {
            $this->mpdf = new mPDF();
    
            // $this->mpdf->useSubstitutions = false;
            $this->mpdf->simpleTables = true;
            // $this->mpdf->SetAutoPageBreak(false);
            $this->mpdf->showImageErrors = true;
        
            if (version_compare($this->_version, '6', '>=')) {
                $this->mpdf->autoLangToFont = true;
            }
        }

        return $this->mpdf;
    }

    /**
     * Fix an url matching server address and port.
     *
     * @param string  $url      Url to run fix against.
     * @param boolean $add_port Wether to add the original port.
     *
     * @return string
     */
    private function _fixInternalUrl($url, $add_port = true)
    {
        $port = parse_url($url, PHP_URL_PORT);

        return str_replace(
            env('HTTP_HOST'),
            env('REMOTE_ADDR') . ($add_port && $port ? ':' . $port : ''),
            $url
        );
    }

    /**
     * [_getAssetByCurl description]
     *
     * @method _getAssetByCurl
     * @param  [type] $url          [description]
     * @param  mixed  $base64Encode
     */
    private function _getAssetByCurl($url, $base64Encode = false)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        // $this->_verifySsl = defined('IS_DEV') && IS_DEV;
        $this->_verifySsl = false;

        $url = $this->_fixInternalUrl($url);

        $class = $this;

        $cache_key = 'asset_by_curl_' . basename($url) . '_' . (false === $class->_verifySsl ? 'no-verify_' : '') . md5($url);
        $cache_group = 'assets';
        $asset_data = Cache::remember(
            $cache_key,
            function () use ($url, $class) {
                try {
                    $ch = curl_init($url);
                    if (false === $ch) {
                        throw new Exception('Failed to initialize');
                    }
                    $options = [
                        CURLOPT_URL => $url,
                        CURLOPT_HEADER => 0,
                        CURLOPT_RETURNTRANSFER => 1,
                    ];
                    if (false === $class->_verifySsl) {
                        $options += [
                            CURLOPT_SSL_VERIFYHOST => 0,
                            CURLOPT_SSL_VERIFYPEER => 0,
                        ];
                    }
                    curl_setopt_array($ch, $options);
                    $content = curl_exec($ch);
                    if (false === $content) {
                        throw new Exception(curl_error($ch), curl_errno($ch));
                    }
                } catch (Exception $e) {
                    trigger_error(
                        sprintf(
                            'Curl failed with error #%d: %s',
                            $e->getCode(),
                            $e->getMessage()
                        ),
                        E_USER_ERROR
                    );
                }
                $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if (404 === $code) {
                    return null;
                }
                return compact('content', 'mime');
            },
            $cache_group
        );

        if (!$asset_data) {
            return false;
        }
        extract($asset_data);

        if ($base64Encode) {
            return $this->_base64Encode($content, $mime);
        }

        return $content;
    }

    /**
     * [_base64Encode description]
     *
     * @method _base64Encode
     * @param  [type]     $uri     [description]
     * @param  mixed      $content
     * @param  null|mixed $mime
     * @return [type]     [description]
     */
    private function _base64Encode($content, $mime = null)
    {
        $encode = true;
        if (is_null($mime) 
            || !$mime
        ) {
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
     * [inlineStylesheets description]
     *
     * @param string $content
     *
     * @return string
     */
    protected function inlineStylesheets($content)
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
            $assetContent = $this->_getAssetByCurl($asset[1]);
            if ($assetContent) {
                $assetContent = preg_replace('@\s*/\*.+\*/\s*@mU', '', $assetContent);
                $content = str_replace($asset[0], '<style>' . $assetContent . '</style>', $content);
            }
            unset($assetContent);
        }
        return $content;
    }

    /**
     * 
     */
    protected function getAbsoluteUri($uri)
    {
        $replace_strings = [
            env('REQUEST_SCHEME') . '://' . env('SERVER_ADDR') . '/',
            FULL_BASE_URL . '/',
        ];

        $absolute_uri = str_replace(
            $replace_strings,
            '',
            $uri
        );

        if (0 === strpos($absolute_uri, 'course/assets/')) {
            $absolute_uri = str_replace(
                'course/assets/',
                '..' . DS . 'Plugin' . DS . 'Course' . DS . 'webroot' . DS . 'assets' . DS,
                $absolute_uri
            );
        }

        return $absolute_uri;
    }

    /**
     * [inlineImages description]
     *
     * @param string $content
     *
     * @return string
     */
    protected function inlineImages($content)
    {
        $regexp_images = '/<(?:img|image)[^>]*(?:src|xlink:href)=["\']([^>"\']*)["\'].*?>/si';
        preg_match_all($regexp_images, $content, $m, PREG_SET_ORDER);

        $regexp_inline = '/\:url\(["\']?([^>"\']*)["\']?\)/si';
        preg_match_all($regexp_inline, $content, $n, PREG_SET_ORDER);

        if (!count($m)
            && !count($n)
        ) {
            return $content;
        }

        $matches = array_merge((array) $m, (array) $n);
        $replaces = [];
        for ($i = 0, $l = count($matches); $i < $l; $i++) {
            $asset = $matches[$i];
            $uri = $asset[1];
            if (false === strpos($uri, $this->_host)
                && false === strpos($uri, env('SERVER_ADDR'))
            ) {
                continue;
            }
            
            $absolute_uri = $this->getAbsoluteUri($uri);

            if (file_exists(WWW_ROOT . $absolute_uri)) {
                $info = pathinfo(WWW_ROOT . $absolute_uri);
                $file_content = file_get_contents(WWW_ROOT . $absolute_uri);
                switch (true) {
                case 'svg' === $info['extension']
                    && false !== strpos(substr($file_content, 0, 64), '<svg'):
                    $replaces[$asset[0]] = $file_content;
                    break;
                case 'jpg' === $info['extension']:
                case 'png' === $info['extension']:
                    // $replaces['src="' . $uri . '"'] = 'src="' . str_replace($this->_host, env('SERVER_ADDR'), $uri) . '"';
                    // $key = basename($uri);
                    // $replaces['src="' . $uri . '"'] = 'src="var:' . $key . '"';
                    // $this->_images[$key] = $file_content;
                    // unset($file_content);
                    $replaces[$asset[0]] = str_replace($uri, $this->_base64Encode($file_content), $asset[0]);
                    break;
                }
            } else {
                $assetContent = $this->_getAssetByCurl($uri, true);
                if ($assetContent
                    && false === strpos($assetContent, 'image/png')
                ) {
                    $replaces['src="' . $uri . '"'] = 'src="' . $assetContent . '"';
                }
            }
        }

        if (count($replaces)) {
            $content = str_replace(array_keys($replaces), $replaces, $content);
        }

        return $content;
    }

    /**
     * [replaceAssetUrls description]
     *
     * @param string $content
     *
     * @return string
     */
    protected function replaceAssetUrls($content)
    {
        // Rewrite internal ip asset urls
        $internal_ip = env('SERVER_ADDR');
        if ($internal_ip/* && in_array($internal_ip, ['10.251.3.68','37.61.205.200','37.61.205.123','37.61.206.113'])*/) {
            // $content = str_replace(Router::url('/', true), 'https://www.ibb.com/', $content);
            $content = str_replace($internal_ip, $this->_host, $content);
            $content = str_replace(WWW_ROOT, FULL_BASE_URL . '/', $content);
            $content = str_replace($this->_host . '/css', $this->_host . '/theme/Ibb/css', $content);
        }

        if (!! Configure::read('Site.forcehttps')) {
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

        set_time_limit(1000);
        ini_set('memory_limit', '2048M');

        $content = $this->_Pdf->html();

        Configure::write('Site.forcehttps', true);

        $content = $this->replaceAssetUrls($content);
        $content = $this->inlineStylesheets($content);
        $content = $this->inlineImages($content);

        // catch output if debug is true
        if (Configure::read('debug') > 0) {
            echo $content;
            exit;
        }

        // fix utf-8 error
        // https://github.com/osTicket/osTicket/issues/1395#issuecomment-266522612
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        $this->mpdf->writeHTML($content);

        $download = !! $this->config['download'];

        if ('latest' === $this->_version
            || version_compare($this->_version, '7', '>=')
        ) {
            $dest = $download ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE;
        } else {
            $dest = $download ? "D" : "S";
        }

        return $this->mpdf->Output('', $dest);
    }
}

/* end of file MpdfEngine.php */
