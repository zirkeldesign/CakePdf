<?php

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
     * Constructor
     *
     * @param $Pdf CakePdf instance
     */
    public function __construct(CakePdf $Pdf)
    {
        parent::__construct($Pdf);
        App::import('Vendor', 'CakePdf.Mpdf', ['file' => 'mpdf' . DS . 'mpdf.php']);
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

        // Rewrite internal ip asset urls
        $internal_ip = env('SERVER_ADDR');
        if ($internal_ip && in_array($internal_ip, ['10.251.3.68'])) {
            // $content = str_replace(Router::url('/', true), 'https://www.ibb.com/', $content);
            // $content = str_replace('10.251.3.68', 'www.ibb.com', $content);
            $content = str_replace($internal_ip . '/css', $internal_ip . '/theme/Ibb/css', $content);
        }

        // catch output if debug is true
        if (Configure::read('debug') > 0) {
            echo $content;
            die;
        }

        // https://github.com/osTicket/osTicket/issues/1395#issuecomment-266522612
        $content = mb_convert_encoding($content.'', 'UTF-8', 'UTF-8');

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
