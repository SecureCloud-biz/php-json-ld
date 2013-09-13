<?php
/**
 * PHP unit tests for JSON-LD.
 *
 * @author Dave Longley
 *
 * Copyright (c) 2013 Digital Bazaar, Inc. All rights reserved.
 */
require_once('jsonld.php');

class JsonLdTestCase extends PHPUnit_Framework_TestCase {
  /**
   * Called after all tests; optionally outputs an earl report.
   */
  public static function tearDownAfterClass() {
    global $EARL, $OPTIONS;
    if(isset($OPTIONS['-e'])) {
      $filename = $OPTIONS['-e'];
      echo "Writing EARL report to: $filename\n";
      $EARL->write($filename);
    }
  }

  /**
   * Runs this test case. Overridden to attach to EARL report w/o need for
   * an external XML configuration file.
   *
   * @param PHPUnit_Framework_TestResult $result the test result.
   */
  public function run(PHPUnit_Framework_TestResult $result = NULL) {
    global $EARL;
    $EARL->attach($result);
    parent::run($result);
  }

  /**
   * Tests expansion.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group expand
   * @dataProvider expandProvider
   */
  public function testExpand($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $options = $test->createOptions();
    $test->run('jsonld_expand', array($input, $options));
  }

  /**
   * Tests compaction.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group compact
   * @dataProvider compactProvider
   */
  public function testCompact($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $context = $test->readProperty('context');
    $options = $test->createOptions();
    $test->run('jsonld_compact', array($input, $context, $options));
  }

  /**
   * Tests flatten.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group flatten
   * @dataProvider flattenProvider
   */
  public function testFlatten($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $context = $test->readProperty('context');
    $options = $test->createOptions();
    $test->run('jsonld_flatten', array($input, $context, $options));
  }

  /**
   * Tests serialization to RDF.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group toRdf
   * @dataProvider toRdfProvider
   */
  public function testToRdf($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $options = $test->createOptions(array('format' => 'application/nquads'));
    $test->run('jsonld_to_rdf', array($input, $options));
  }

  /**
   * Tests deserialization from RDF.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group fromRdf
   * @dataProvider fromRdfProvider
   */
  public function testFromRdf($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $options = $test->createOptions(array('format' => 'application/nquads'));
    $test->run('jsonld_from_rdf', array($input, $options));
  }

  /**
   * Tests framing.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group frame
   * @dataProvider frameProvider
   */
  public function testFrame($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $frame = $test->readProperty('frame');
    $options = $test->createOptions();
    $test->run('jsonld_frame', array($input, $frame, $options));
  }

  /**
   * Tests normalization.
   *
   * @param JsonLdTest $test the test to run.
   *
   * @group normalize
   * @depends toRdf
   * @dataProvider normalizeProvider
   */
  public function testNormalize($test) {
    $this->test = $test;
    $input = $test->readProperty('input');
    $options = $test->createOptions(array('format' => 'application/nquads'));
    $test->run('jsonld_compact', array($input, $options));
  }

  public function expandProvider() {
    return new JsonLdTestIterator('jld:ExpandTest');
  }

  public function compactProvider() {
    return new JsonLdTestIterator('jld:CompactTest');
  }

  public function flattenProvider() {
    return new JsonLdTestIterator('jld:FlattenTest');
  }

  public function toRdfProvider() {
    return new JsonLdTestIterator('jld:ToRDFTest');
  }

  public function fromRdfProvider() {
    return new JsonLdTestIterator('jld:fromRDFTest');
  }

  public function normalizeProvider() {
    return new JsonLdTestIterator('jld:NormalizeTest');
  }

  public function frameProvider() {
    return new JsonLdTestIterator('jld:FrameTest');
  }
}

class JsonLdManifest {
  public function __construct($data, $filename) {
    $this->data = $data;
    $this->filename = $filename;
    $this->dirname = dirname($filename);
  }

  public function load(&$tests) {
    $sequence = JsonLdProcessor::getValues($this->data, 'sequence');
    foreach($sequence as $entry) {
      if(is_string($entry)) {
        $filename = join(
          DIRECTORY_SEPARATOR, array($this->dirname, $entry));
        $entry = Util::readJson($filename);
      }
      else {
        $filename = $this->filename;
      }

      // entry is another manifest
      if(JsonLdProcessor::hasValue($entry, '@type', 'mf:Manifest')) {
        $manifest = new JsonLdManifest($entry, $filename);
        $manifest->load($tests);
      }
      // assume entry is a test
      else {
        $test = new JsonLdTest($this, $entry, $filename);
        $types = JsonLdProcessor::getValues($test->data, '@type');
        foreach($types as $type) {
          if(!isset($tests[$type])) {
            $tests[$type] = array();
          }
          $tests[$type][] = $test;
        }
      }
    }
  }
}

class JsonLdTest {
  public function __construct($manifest, $data, $filename) {
    $this->manifest = $manifest;
    $this->data = $data;
    $this->filename = $filename;
    $this->dirname = dirname($filename);
    $this->isPositive = JsonLdProcessor::hasValue(
      $data, '@type', 'jld:PositiveEvaluationTest');
    $this->isNegative = JsonLdProcessor::hasValue(
      $data, '@type', 'jld:NegativeEvaluationTest');

    // generate test name
    $this->name = $manifest->data->name . ' ' . substr($data->{'@id'}, 2);

    // expand @id and input base
    $data->{'@id'} = ($manifest->data->baseIri .
      basename($manifest->filename) . $data->{'@id'});
    $this->base = $manifest->data->baseIri . $data->input;
  }

  public function run($fn, $params) {
    // read expected data
    if($this->isNegative) {
      $this->expected = $this->data->expect;
    }
    else {
      $this->expected = $this->readProperty('expect');
    }

    try {
      $this->actual = call_user_func_array($fn, $params);
      if($this->isNegative) {
        throw new Exception('Expected an error; one was not raised.');
      }
      PHPUnit_Framework_TestCase::assertEquals($this->expected, $this->actual);
    }
    catch(Exception $e) {
      if($this->isPositive) {
        throw $e;
      }
      $this->actual = $this->getJsonLdErrorCode($e);
      PHPUnit_Framework_TestCase::assertEquals($this->expected, $this->actual);
    }
  }

  public function readProperty($property) {
    $data = $this->data;
    if(!property_exists($data, $property)) {
      return null;
    }
    $filename = join(
      DIRECTORY_SEPARATOR, array($this->dirname, $data->{$property}));
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    if($extension === 'jsonld') {
      return Util::readJson($filename);
    }
    return Util::readFile($filename);
  }

  public function createOptions($opts=array()) {
    $http_options = array(
      'contentType', 'httpLink', 'httpStatus', 'redirectTo');
    $test_options = (property_exists($this->data, 'option') ?
      $this->data->option : array());
    $options = array();
    foreach($test_options as $k => $v) {
      if(!in_array($k, $http_options)) {
        $options[$k] = $v;
      }
    }
    $options['documentLoader'] = $this->createDocumentLoader();
    $options = array_merge($options, $opts);
    if(isset($options['expandContext'])) {
      $filename = join(
        DIRECTORY_SEPARATOR, array($this->dirname, $options['expandContext']));
      $options['expandContext'] = Util::readJson($filename);
    }
    return $options;
  }

  public function createDocumentLoader() {
    global $jsonld_default_load_document;
    $base = 'http://json-ld.org/test-suite';
    $loader = $jsonld_default_load_document;
    $test = $this;

    $load_locally = function($url) use ($test, $base) {
      $doc = (object)array(
        'contextUrl' => null, 'documentUrl' => $url, 'document' => null);
      $options = (property_exists($test->data, 'option') ?
        $test->data->option : null);
      if($options and $url === $test->base) {
        if(property_exists($options, 'redirectTo') &&
          property_exists($options, 'httpStatus') &&
          $options->httpStatus >= '300') {
          $doc->documentUrl = ($test->manifest->data->{'baseIri'} .
            $options->redirectTo);
        }
        else if(property_exists($options, 'httpLink')) {
          $content_type = (property_exists($options, 'contentType') ?
            $options->contentType : null);
          $extension = pathinfo($url, PATHINFO_EXTENSION);
          if(!$content_type && $extension === '.jsonld') {
            $content_type = 'application/ld+json';
          }
          $link_header = $options->httpLink;
          if(is_array($link_header)) {
            $link_header = join(',', $link_header);
          }
          $link_header = jsonld_parse_link_header(
            $link_header)['http://www.w3.org/ns/json-ld#context'] ?: null;
          if($link_header && $content_type !== 'application/ld+json') {
            if(is_array($link_header)) {
              throw new Exception('multiple context link headers');
            }
            $doc->{'contextUrl'} = $link_header['target'];
          }
        }
      }
      global $ROOT_MANIFEST_DIR;
      $filename = $ROOT_MANIFEST_DIR .
        substr($doc->{'documentUrl'}, strlen($base));
      try {
        $doc->{'document'} = Util::readJson($filename);
      }
      catch(Exception $e) {
        throw new Exception('loading document failed');
      }
      return $doc;
    };

    $local_loader = function($url) use ($loader, $load_locally) {
      // always load remote-doc and non-base tests remotely
      /*if(strpos($url, $base) !== 0 ||
        $test->manifest->data['name'] === 'Remote document') {
        return call_user_func($loader, $url);
      }*/

      // attempt to load locally
      return call_user_func($load_locally, $url);
    };

    return $local_loader;
  }

  public function getJsonLdErrorCode($err) {
    if($err instanceof JsonLdException) {
      if($err->getCode()) {
        return $err->getCode();
      }
      if($err->cause) {
        return $this->getJsonLdErrorCode($err->cause);
      }
    }
    return $err->getMessage();
  }
}

class JsonLdTestIterator implements Iterator {
  /**
   * The current test index.
   */
  protected $index = 0;

  /**
   * The total number of tests.
   */
  protected $count = 0;

  /**
   * Creates a TestIterator.
   *
   * @param string $type the type of tests to iterate over.
   */
  public function __construct($type) {
    global $TESTS;
    if(isset($TESTS[$type])) {
      $this->tests = $TESTS[$type];
    }
    else {
      $this->tests = array();
    }
    $this->count = count($this->tests);
  }

  /**
   * Gets the parameters for the next test.
   *
   * @return assoc the parameters for the next test.
   */
  public function current() {
    return array('test' => $this->tests[$this->index]);
  }

  /**
   * Gets the current test number.
   *
   * @return int the current test number.
   */
  public function key() {
    return $this->index;
  }

  /**
   * Proceeds to the next test.
   */
  public function next() {
    $this->index += 1;
  }

  /**
   * Rewinds to the first test.
   */
  public function rewind() {
    $this->index = 0;
  }

  /**
   * Returns true if there are more tests to be run.
   *
   * @return bool true if there are more tests to be run.
   */
  public function valid() {
    return $this->index < $this->count;
  }
}

class EarlReport implements PHPUnit_Framework_TestListener {
  public function __construct() {
    $this->result = null;
    $this->report = (object)array(
      '@context' => (object)array(
        'doap' => 'http://usefulinc.com/ns/doap#',
        'foaf' => 'http://xmlns.com/foaf/0.1/',
        'dc' => 'http://purl.org/dc/terms/',
        'earl' => 'http://www.w3.org/ns/earl#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        'doap:homepage' => (object)array('@type' => '@id'),
        'doap:license' => (object)array('@type' => '@id'),
        'dc:creator' => (object)array('@type' => '@id'),
        'foaf:homepage' => (object)array('@type' => '@id'),
        'subjectOf' => (object)array('@reverse' => 'earl:subject'),
        'earl:assertedBy' => (object)array('@type' => '@id'),
        'earl:mode' => (object)array('@type' => '@id'),
        'earl:test' => (object)array('@type' => '@id'),
        'earl:outcome' => (object)array('@type' => '@id'),
        'dc:date' => (object)array('@type' => 'xsd:date')
      ),
      '@id' => 'https://github.com/digitalbazaar/php-json-ld',
      '@type' => array('doap:Project', 'earl:TestSubject', 'earl:Software'),
      'doap:name' => 'php-json-ld',
      'dc:title' => 'php-json-ld',
      'doap:homepage' => 'https://github.com/digitalbazaar/php-json-ld',
      'doap:license' => 'https://github.com/digitalbazaar/php-json-ld/blob/master/LICENSE',
      'doap:description' => 'A JSON-LD processor for PHP',
      'doap:programming-language' => 'PHP',
      'dc:creator' => 'https://github.com/dlongley',
      'doap:developer' => (object)array(
        '@id' => 'https://github.com/dlongley',
        '@type' => array('foaf:Person', 'earl:Assertor'),
        'foaf:name' => 'Dave Longley',
        'foaf:homepage' => 'https://github.com/dlongley'
      ),
      'dc:date' => array(
        '@value' => gmdate('Y-m-d'),
        '@type' => 'xsd:date'
      ),
      'subjectOf' => array()
    );
  }

  /**
   * Attaches to the given test result, if not yet attached.
   *
   * @param PHPUnit_Framework_Test $result the result to attach to.
   */
  public function attach(PHPUnit_Framework_TestResult $result) {
    if(!$this->result) {
      $this->result = $result;
      $result->addListener($this);
    }
  }

  /**
   * Adds an assertion to this EARL report.
   *
   * @param JsonLdTest $test the JsonLdTest for the assertion is for.
   * @param bool $passed whether or not the test passed.
   */
  public function addAssertion($test, $passed) {
    $this->report->{'subjectOf'}[] = (object)array(
      '@type' => 'earl:Assertion',
      'earl:assertedBy' => $this->report->{'doap:developer'}->{'@id'},
      'earl:mode' => 'earl:automatic',
      'earl:test' => $test->data->{'@id'},
      'earl:result' => (object)array(
        '@type' => 'earl:TestResult',
        'dc:date' => gmdate(DateTime::ISO8601),
        'earl:outcome' => $passed ? 'earl:passed' : 'earl:failed'
      )
    );
    return $this;
  }

  /**
   * Writes this EARL report to a file.
   *
   * @param string $filename the name of the file to write to.
   */
  public function write($filename) {
    $fd = fopen($filename, 'w');
    fwrite($fd, Util::jsonldEncode($this->report));
    fclose($fd);
  }

  public function endTest(PHPUnit_Framework_Test $test, $time) {
    $this->addAssertion($test->test, true);
  }

  public function addError(
    PHPUnit_Framework_Test $test, Exception $e, $time) {
    $this->addAssertion($test->test, false);
  }

  public function addFailure(
    PHPUnit_Framework_Test $test,
    PHPUnit_Framework_AssertionFailedError $e, $time) {
    $this->addAssertion($test->test, false);
    if($this->result->shouldStop()) {
      printf("\nFAILED Test: %s\n", $test->test->name);
      printf("EXPECTED: %s\n", Util::jsonldEncode($test->test->expected));
      printf("ACTUAL: %s\n", Util::jsonldEncode($test->test->actual));
      exit(0);
    }
  }

  public function addIncompleteTest(
    PHPUnit_Framework_Test $test, Exception $e, $time) {
    $this->addAssertion($test->test, false);
  }

  public function addSkippedTest(
    PHPUnit_Framework_Test $test, Exception $e, $time) {
    printf("Test '%s' has been skipped.\n", $test->test->data->{'@id'});
  }

  public function startTest(PHPUnit_Framework_Test $test) {}
  public function startTestSuite(PHPUnit_Framework_TestSuite $suite) {}
  public function endTestSuite(PHPUnit_Framework_TestSuite $suite) {}
}

class Util {
  public static function readFile($filename) {
    return file_get_contents($filename);
  }

  public static function readJson($filename) {
    return json_decode(file_get_contents($filename));
  }

  public static function readNQuads($filename) {
    return readFile($filename);
  }

  public static function jsonldEncode($input) {
    // newer PHP has a flag to avoid escaped '/'
    if(defined('JSON_UNESCAPED_SLASHES')) {
      $options = JSON_UNESCAPED_SLASHES;
      if(defined('JSON_PRETTY_PRINT')) {
        $options |= JSON_PRETTY_PRINT;
      }
      $json = json_encode($input, $options);
    }
    else {
      // use a simple string replacement of '\/' to '/'.
      $json = str_replace('\\/', '/', json_encode($input));
    }
    return $json;
  }
}

// tests to skip
$SKIP_TESTS = array();

// root manifest directory
$ROOT_MANIFEST_DIR;

// parsed tests; keyed by type
$TESTS = array();

// parsed command line options
$OPTIONS = array();

// EARL Report
$EARL = new EarlReport();

// parse command line options
global $argv;
$args = $argv;
$total = count($args);
$start = false;
for($i = 0; $i < $total; ++$i) {
  $arg = $args[$i];
  if(!$start) {
    if(realpath($arg) === realpath(__FILE__)) {
      $start = true;
    }
    continue;
  }
  if($arg[0] !== '-') {
    break;
  }
  $i += 1;
  $OPTIONS[$arg] = $args[$i];
}
if(!isset($OPTIONS['-d'])) {
  $dvar = 'path to json-ld.org/test-suite';
  $evar = 'file to write EARL report to';
  echo "php-json-ld Tests\n";
  echo "Usage: phpunit test.php -d <$dvar> [-e <$evar>]\n\n";
  exit(0);
}

// load root manifest
$ROOT_MANIFEST_DIR = realpath($OPTIONS['-d']);
$filename = join(
  DIRECTORY_SEPARATOR, array($ROOT_MANIFEST_DIR, 'manifest.jsonld'));
$root_manifest = Util::readJson($filename);
$manifest = new JsonLdManifest($root_manifest, $filename);
$manifest->load($TESTS);

/* end of file, omit ?> */
