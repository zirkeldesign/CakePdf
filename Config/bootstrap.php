<?php
/**
 * bootstrap.php
 *
 * @author  Daniel Sturm
 * @build   2017-07-18
 */

App::build(array('Pdf' => array('%s' . 'Pdf' . DS)), App::REGISTER);
App::build(array('Pdf/Engine' => array('%s' . 'Pdf/Engine' . DS)), App::REGISTER);
App::uses('PdfView', 'CakePdf.View');

/* end of file bootstrap.php */
