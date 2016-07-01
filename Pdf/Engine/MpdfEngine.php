<?php

App::uses('AbstractPdfEngine', 'CakePdf.Pdf/Engine');
App::uses('Multibyte', 'I18n');

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
        App::import('Vendor', 'CakePdf.Mpdf', array('file' => 'mpdf' . DS . 'mpdf.php'));
    }

/**
 * Generates Pdf from html
 *
 * @return string raw pdf data
 */
    public function output()
    {
        //mPDF often produces a whole bunch of errors, although there is a pdf created when debug = 0
        //Configure::write('debug', 0);
        $content = $this->_Pdf->html();
        $internal_ip = env('SERVER_ADDR');
        if ($internal_ip && in_array($internal_ip, array('10.251.3.68'))) {
            $url = str_replace(Router::url('/', true), 'https://www.ibb.com/', $content);
            $url = str_replace('10.251.3.68', 'www.ibb.com', $content);
        }
        if (Configure::read('debug') > 0) {
            echo $content;
            die;
        }
        error_reporting(0);
        $MPDF = new mPDF();
        $MPDF->useSubstitutions = false;
        $MPDF->simpleTables = true;
        $MPDF->SetAutoPageBreak(false);
        $MPDF->writeHTML($content);
        return $MPDF->Output('', 'S');
    }
}
