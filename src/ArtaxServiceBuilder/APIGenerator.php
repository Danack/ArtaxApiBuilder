<?php


namespace ArtaxServiceBuilder;


use Danack\Code\Generator\ClassGenerator;
use Danack\Code\Generator\GeneratorInterface;
use Danack\Code\Generator\DocBlockGenerator;
use Danack\Code\Generator\InterfaceGenerator;
use Danack\Code\Generator\MethodGenerator;
use Danack\Code\Generator\ParameterGenerator;
use Danack\Code\Generator\AbstractMemberGenerator;
use Danack\Code\Generator\DocBlock\Tag\GenericTag;
use Danack\Code\Generator\DocBlock\Tag\ParamTag;
use Danack\Code\Generator\DocBlock\Tag\ReturnTag;
use Danack\Code\Generator\DocBlock\Tag\ThrowsTag;


use Danack\Code\Generator\PropertyGenerator;

function getNamespace($namespaceClass) {

    if (is_object($namespaceClass)) {
        $namespaceClass = get_class($namespaceClass);
    }

    $lastSlashPosition = mb_strrpos($namespaceClass, '\\');

    if ($lastSlashPosition !== false) {
        return mb_substr($namespaceClass, 0, $lastSlashPosition);
    }

    return "";
}


function getClassName($namespaceClass) {
    $lastSlashPosition = mb_strrpos($namespaceClass, '\\');

    if ($lastSlashPosition !== false) {
        return mb_substr($namespaceClass, $lastSlashPosition + 1);
    }

    return $namespaceClass;
}


/**
 * @param $savePath
 * @throws \RuntimeException
 */
function saveFile($savePath, $fqcn, $text) {

    $filename = str_replace('\\', '/', $fqcn);

    $fileHeader = <<< END
<?php

//Auto-generated by ArtaxServiceBuilder - https://github.com/Danack/ArtaxServiceBuilder
//
//Do not be surprised when any changes to this file are over-written.
//

END;

    $outputFilename = $savePath.'/'.$filename.'.php';
    @mkdir(dirname($outputFilename), 0777, true);
    $written = @file_put_contents($outputFilename, $fileHeader.$text);

    if ($written == false) {
        throw new APIBuilderException("Failed to write file $filename.");
    }
}





class APIGenerator {

    /**
     * @var ClassGenerator
     */
    private $classGenerator;

    private $interfaceGenerator;
    private $outputPath;
    private $namespace;
    private $parameterTranslations = [];
    private $apiParameters = [];
    private $delimiter = '#';
    private $operationNamespace;
    private $useNames = [];
    
    /**
     * Fully qualified classname - aka namespace + classname
     * @var
     */
    private $fqcn;
    
    private $fqExceptionClassname = null;

    /**
     * @var string[] The interfaces the generated API class should implement
     */
    private $interfaces = [];
    
    private $interfaceName;

    /**
     * @var bool Whether the API requires Oauth1 signing service.
     * Defaults to false.
     */
    private $requiresOauth1 = false;

    private $includeMethods = [];

    private $excludeMethods = [];

    /**
     * @var callable
     */
    private $normalizeMethodCallable = null;

    /**
     * @var callable
     */
    private $normalizeClassCallable = null;

    /**
     * @var \ArtaxServiceBuilder\OperationDefinition[]
     */
    private $operations = [];
    
    private $authErrorAsException = false;
    

    /**
     * @param $outputPath
     * @param $constructorParams
     */
    function __construct($outputPath, $constructorParams) {
        $this->classGenerator = new ClassGenerator();
        $this->interfaceGenerator = new InterfaceGenerator(); 
        $this->constructorParams = $constructorParams;
        $this->outputPath = $outputPath;
    }

    /**
     * @param $requiresOauth1
     */
    function setRequiresOauth1($requiresOauth1) {
        $this->requiresOauth1 = $requiresOauth1;
    }
    

    /**
     * Whether to throw AuthException when an authorization error occurs.
     * 
     * The service can generate code to handle authorisation errors in one of two ways:
     *
     * i) Throw an AuthException.
     * ii) Retutn an AuthorisationRequiredResponse
     * 
     * @param $authErrorAsException
     */
    function setAuthErrorAsException($authErrorAsException) {
        $this->authErrorAsException = $authErrorAsException;
    }

    /**
     * @return mixed
     */
    function getFQCN() {
        return $this->fqcn;
    }

    /**
     * Get the list of parameters (by name) that should only exist at an API level, rather
     * than being copied all over the API.
     * @return mixed
     */
    function getAPIParameters() {
        return $this->apiParameters;
    }

    /**
     * @return OperationDefinition[]
     */
    function getOperations() {
        return $this->operations;
    }

    /**
     * @param $interface
     */
    function addInterface($interface) {
        $this->interfaces[] = $interface;
    }

    /**
     * @param $parameter
     * @return mixed
     */
    function translateParameter($parameter) {
        if (array_key_exists($parameter, $this->parameterTranslations) == true) {
            return $this->parameterTranslations[$parameter];
        }

        return $parameter;
    }


    /**
     * @param $fqcn
     */
    function setFQCN($fqcn) {
        $this->fqcn = $fqcn;
        $this->classGenerator->setFQCN($fqcn);

        if ($this->fqExceptionClassname == null) {
            $this->fqExceptionClassname =  $this->fqcn.'Exception';
        }

        $this->namespace = \ArtaxServiceBuilder\getNamespace($this->fqcn);
    }

    /**
     * @param $operationNamespace
     */
    function setOperationNamespace($operationNamespace) {
        $this->operationNamespace = $operationNamespace;
    }
    
    /**
     * 
     */
    function addConstructorMethod() {
        if (count($this->constructorParams)) {
            $methodGenerator = new MethodGenerator('__construct');

            $body = '';
            $params = [];

            //Every API needs the Artax\Client object to send requests
            $param = new ParameterGenerator('client', 'Artax\Client', null);
            $params[] = $param;
            $body .= '$this->client = $client;'.PHP_EOL;
            
            //Add the params
            foreach ($this->constructorParams as $constructorParam) {
                $param = new ParameterGenerator($constructorParam);
                $params[] = $param;
                $body .= sprintf('$this->%s = $%s;', $constructorParam, $constructorParam);
                $body .= PHP_EOL;
            }

            //Add an oauth signing service if the API needs one
            if ($this->requiresOauth1 == true) {
                $param = new ParameterGenerator('oauthService', 'ArtaxServiceBuilder\Service\Oauth1', null);
                $param->setDefaultValue(null);
                $params[] = $param;
                $body .= sprintf('$this->%s = $%s;', 'oauthService', 'oauthService');
                $body .= PHP_EOL;
            }

            $methodGenerator->setBody($body);
            $methodGenerator->setParameters($params);
            $this->classGenerator->addMethodFromGenerator($methodGenerator);
        }
    }
    

    /**
     *
     */
    function addExecMethod() {
        $methodGenerator = new MethodGenerator('execute');
        $requestParam = new ParameterGenerator('request', 'Artax\Request');
        $successStatusParam = new ParameterGenerator('successStatuses', 'array', []);
        $methodGenerator->setParameters([$requestParam, $successStatusParam]);
        $methodGenerator->setBody($this->getCallBody());

        $tags = [];
        $tags[] = new GenericTag(
            'param',
            '$request \Artax\Request The request to send.'
        );
        $tags[] = new GenericTag(
            'param',
            '$successStatuses array A list of acceptable success statuses.'
        );
        $tags[] = new GenericTag(
            'return',
            '\Artax\Response  The response from Artax'
        );

        $docBlockGenerator = new DocBlockGenerator('execute');
        $docBlockGenerator->setLongDescription("Sends a request to the API synchronously");
        $docBlockGenerator->setTags($tags);
        $methodGenerator->setDocBlock($docBlockGenerator);

        $this->classGenerator->addMethodFromGenerator($methodGenerator);
    }


    /**
     *
     */
    function addExecAsyncMethod() {
        $methodGenerator = new MethodGenerator('executeAsync');
        $requestParam = new ParameterGenerator('request', 'Artax\Request');
        $operationParam = new ParameterGenerator('operation', 'ArtaxServiceBuilder\Operation');
        $callableParam = new ParameterGenerator('callback', 'callable');

        $methodGenerator->setParameters([$requestParam, $operationParam, $callableParam]);

        $body = <<< 'END'
$promise = $this->client->request($request);
$promise->when(function(\Exception $error = null, Response $response = null) use ($callback, $operation) {

    if($error) {
        $callback($error, $response);
        return;
    }

    $status = $response->getStatus();
    if ($status < 200 || $status >= 300) {
        $exception = new \Exception("Status $status is not treated as OK.");
        $callback($exception, $response);
        return;
    }

    try {
        $parsedResponse = $operation->processResponse($response);
        $callback(null, $parsedResponse);
    }
    catch(\Exception $e) {
        $exception = new \Exception("Exception parsing response: ".$e->getMessage(), 0, $e);
        $callback($exception, "Error parsing response", null);
    }
});

return $promise;
END;

        $methodGenerator->setBody($body);

        $tags = [];
        $tags[] = new GenericTag(
            'param',
            '\ArtaxServiceBuilder\Operation $operation The operation to perform'
        );
        $tags[] = new GenericTag(
            'param',
            'callable $callback The callback to call on completion/response. Parameters should be blah blah blah'
        );


        $tags[] = new GenericTag(
            'return',
            '\After\Promise A promise to resolve the call at some time.'
        );
        
        $docBlockGenerator = new DocBlockGenerator('executeAsync');
        $docBlockGenerator->setLongDescription("Execute an operation asynchronously.");
        $docBlockGenerator->setTags($tags);
        $methodGenerator->setDocBlock($docBlockGenerator);
        $this->classGenerator->addMethodFromGenerator($methodGenerator);
        $this->interfaceGenerator->addMethodFromGenerator($methodGenerator);
    }

    /**
     * 
     */
    function addSignMethod() {
        if ($this->requiresOauth1 !== true) {
            return;
        }

        $methodGenerator = new MethodGenerator('signRequest');
        $requestParam = new ParameterGenerator('request', 'Artax\Request');
        $methodGenerator->setParameters([$requestParam]);
        
        $body = 'if ($this->oauthService == null) {'.PHP_EOL;;
        $body .= '    throw new \ArtaxServiceBuilder\ArtaxServiceException("oauthService is null, so cannot call request that requires oauth.");'.PHP_EOL;;
        $body .= '}'.PHP_EOL;;

        $body .= 'return $this->oauthService->signRequest($request);'.PHP_EOL;
        $methodGenerator->setBody($body);
        $this->classGenerator->addMethodFromGenerator($methodGenerator);
    }


    /**
     * 
     */
    function addPrepareMethod() {
        $methodGenerator = new MethodGenerator('prepareAPI');
        $methodGenerator->setParameters(['url', 'parameters']);
        $methodGenerator->setBody($this->getPrepareBody());
        $this->classGenerator->addMethodFromGenerator($methodGenerator);
    }


    /**
     * @return string
     */
    function getPrepareBody() {

        $body = <<< 'END'

$request = new \Artax\Request();
$fullURL = $url.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
$request->setUri($fullURL);

return $request;
END;
        
        return $body;
    }
    
    
    /**
     * @return string
     */
    function getCallBody() {

        $body = <<< 'END'
$promise = $this->client->request($request);
$response = $promise->wait();
/** @var $response \Artax\Response */
$status = $response->getStatus();
$status = intval($status);

if ($successStatuses != null  && in_array($status, $successStatuses)) {
    throw new \%s(
        $response, 
        "Status does not match one of ".implode(', ', $successStatuses)
    );
}
else {
    if ($status < 200 || $status >= 300) {
        throw new \%s(
            $response, 
            "Status $status is not 20x success."
        );
    }
}

return $response;
END;
        $body = sprintf(
            $body,
            $this->fqExceptionClassname,
            $this->fqExceptionClassname
        );

        return $body;
    }


    /**
     * @param $methodName
     * @param $functionDefintion
     */
    function addMethod($methodName, OperationDefinition $operation) {
        $this->operations[$methodName] = $operation;
    }

    /**
     * Add the methods that return the operations in the API.
     */
    function addOperationGetter(
        $methodName,
        OperationDefinition $operation,
        OperationGenerator $operationGenerator
    ) {
        $operationName = $this->normalizeMethodName($methodName);
        $operationClassName = $this->normalizeClassName($methodName);
        $methodGenerator = new MethodGenerator($operationName);

        $apiParameters = $this->getAPIParameters();

        $body = '';

        //All required parameters must be passed in when the operation is created.
        $requiredParameters = $operation->getRequiredParams();
        
        $paramsStrings = [];
        $requiredParamsStringsWithDollar = [];
        $tags = [];

        $requiredParamsStringsWithDollar[] = '$this';
        
        foreach($requiredParameters as $requiredParam) {
            if (array_key_exists($requiredParam->getName(), $apiParameters) == true) {
                $requiredParamsStringsWithDollar[] = sprintf(
                    '$this->get%s()',
                    ucfirst($requiredParam->getName())
                );
            }
            else {
                $paramsStrings[] = $requiredParam->getName();
                $tags[] = new GenericTag(
                    'param',
                    $requiredParam->getType().' $'.$requiredParam->getName().' '.$requiredParam->getDescription()
                );
                //TODO - replace with array_map on $paramsStrings
                $requiredParamsStringsWithDollar[] = '$'.$requiredParam->getName();
            }
        }

        $paramString = implode(', ', $requiredParamsStringsWithDollar);
        $methodGenerator->setParameters($paramsStrings);
    
        $tags[] = new GenericTag(
            'return',
            '\\'.$operationGenerator->getFQCN().' The new operation '
        );

        $body .= "\$instance = new $operationClassName($paramString);".PHP_EOL;
        $body .= "return \$instance;".PHP_EOL;
    
        $docBlockGenerator = new DocBlockGenerator($methodName);
        $docBlockGenerator->setLongDescription($operation->getSummary());
        $docBlockGenerator->setTags($tags);

        $methodGenerator->setDocBlock($docBlockGenerator);
        $methodGenerator->setBody($body);
        $this->classGenerator->addMethodFromGenerator($methodGenerator);
        $this->interfaceGenerator->addMethodFromGenerator($methodGenerator);
    }

    /**
     * 
     */
    private function generateMethods() {
        foreach ($this->operations as $methodName => $operation) {
            $operationGenerator = $this->generateOperationClass($methodName, $operation);

            $this->addOperationGetter($methodName, $operation, $operationGenerator);
        }
    }

    /**
     * @param $methodName
     * @return mixed
     */
    public function normalizeMethodName($methodName) {
        if ($this->normalizeMethodCallable != null) {
            $callable = $this->normalizeMethodCallable;
            return $callable($methodName);
        }

        $pattern = '/\.(\w?)/i';

        $replaceCallable = function (array $matches) {
            return strtoupper($matches[1]);
        };

        return preg_replace_callback($pattern, $replaceCallable, $methodName);
    }

    /**
     * @param $methodName
     * @return mixed
     */
    public function normalizeClassName($methodName) {
        if ($this->normalizeClassCallable != null) {
            $callable = $this->normalizeClassCallable;
            return $callable($methodName);
        }

        $pattern = '/\.(\w?)/i';

        $replaceCallable = function (array $matches) {
            return strtoupper($matches[1]);
        };

        return preg_replace_callback($pattern, $replaceCallable, $methodName);
    }

    /**
     * @param $methodName
     * @param OperationDefinition $operation
     */
    private function generateOperationClass($methodName, OperationDefinition $operation) {
        $className = $this->normalizeClassName($methodName);
        
        $operationGenerator = new OperationGenerator(
            $this->operationNamespace,
            $className,
            $operation,
            $this->outputPath,
            $this
        );

        $operationGenerator->setAPIClassname($this->fqcn);
        $operationGenerator->generate();
        $this->addUseStatement($operationGenerator->getFQCN());

        $this->addUseStatement('Artax\Response');
        //$this->addUseStatement('Alert\ReactorFactory');

        return $operationGenerator;
    }


    /**
     * @param $interfaceFQCN
     * @throws APIBuilderException
     */
    function generateInterface($interfaceFQCN) {
        $this->interfaceGenerator->setFQCN($interfaceFQCN);
        $text = $this->interfaceGenerator->generate();
        saveFile($this->outputPath, $interfaceFQCN, $text);
    }

    /**
     * @throws \Exception
     */
    function sanityCheck() {

        if (!$this->fqcn) {
            throw new APIBuilderException("fqcn is empty, cannot generate API.");
        }

        if (!$this->fqExceptionClassname) {
            throw new APIBuilderException("fqExceptionClassname is empty, cannot generate API.");
        }
    }


    /**
     * @throws \ArtaxServiceBuilder\APIBuilderException
     */
    function generateExceptionClass() {
        
        $namespace = \ArtaxServiceBuilder\getNamespace($this->fqcn);
        $classname = \ArtaxServiceBuilder\getClassName($this->fqcn);
        $exceptionClassname = $classname.'Exception';

        if ($namespace) {
            $fqExceptionClassname = $namespace.'\\'.$classname.'Exception';
        }
        else {
            $fqExceptionClassname = $classname.'Exception';
        }

$classText = <<< END

namespace $namespace;

use Artax\Response;

class $exceptionClassname extends \Exception {

    /**
     * @var \Artax\Response
     */
    private \$response;
    
    function __construct(Response \$response, \$message = "", \$code = 0, \Exception \$previous = null) {
        parent::__construct(\$message, \$code, \$previous);
        \$this->response = \$response;
    }
    
    function getResponse() {
        return \$this->response;
    }
}

END;

        saveFile($this->outputPath, $fqExceptionClassname, $classText);
    }

    /**
     * @param array $methodNameArray
     */
    function includeMethods(array $methodNameArray) {
        $quoteCallable = function ($string) {
            return preg_quote($string, $this->delimiter);
        };
        $newIncludes = array_map($quoteCallable, $methodNameArray);
        $this->includeMethods = array_merge($this->includeMethods, $newIncludes);
    }

    /**
     * @param array $methodNameArray
     */
    function excludeMethods(array $methodNameArray) {
        $quoteCallable = function ($string) {
            return preg_quote($string, $this->delimiter);
        };
        $newExcludes = array_map($quoteCallable, $methodNameArray);
        $this->excludeMethods = array_merge($this->excludeMethods, $newExcludes);
    }
    
    
    /**
     * @param $pattern
     */
    function includePattern($pattern) {
        $this->includeMethods[] = $pattern;
    }


    /**
     * @param $pattern
     */
    function excludePattern($pattern) {
        $this->excludeMethods[] = $pattern;
    }



    /**
     * @param $operationName
     * @return bool
     */
    function shouldOperationBeGenerated($operationName) {
        
        $shouldInclude = false;
        
        if (count($this->includeMethods) == 0) {
            //Include filter is empty - so add all operations.
            $shouldInclude = true;
        }
        
        foreach ($this->includeMethods as $includePattern) {
            $includePattern = $this->delimiter.$includePattern.$this->delimiter;
            if (preg_match($includePattern, $operationName)) {
                $shouldInclude = true;
            }
        }

        foreach ($this->excludeMethods as $excludePattern) {
            $includePattern = $this->delimiter.$excludePattern.$this->delimiter;
            if (preg_match($includePattern, $operationName)) {
                $shouldInclude = false;
            }
        }
        

        return $shouldInclude;
    }

    /**
     * Guzzle style service descriptions have a bad habit of requiring certain parameters
     * that ought to be defined once, be defined in every operation that requires them. 
     * For example the Flickr API requires the api_key be set for every operation. It dumb 
     * and counter-productive to define this repeatedly in the API. It should be set once
     * in the API, and that value be passed to every operation that requires it.
     * @param array $apiParameters
     */
    public function addAPIParameters(array $apiParameters) {
        $this->apiParameters = array_merge($this->apiParameters, $apiParameters);
    }

    public function addAPIParameter($name, $type = null) {
        $this->apiParameters[$name] = $type;
    }
    

    /**
     * Allows you to use your preference for formatting of variables in the API library
     * code, while still passing the correct variables to the API end-point.
     * 
     * Because other people have no taste.
     * @param array $translations
     */
    public function addParameterTranslation(array $translations) {
        $this->parameterTranslations = array_merge($this->parameterTranslations, $translations);
    }
    

    /**
     * @param $service
     * @param $operationName
     * @param $baseURL
     * @return OperationDefinition
     * @throws \ArtaxServiceBuilder\APIBuilderException
     */
    function createOperationDescription($service, $operationName, $baseURL) {

        if (isset($service["operations"][$operationName]) == false) {
            throw new APIBuilderException("Service does not have operation named `$operationName`.");
        }

        $operationDescription = $service["operations"][$operationName];

        if (isset($operationDescription['extends'])) {
            $operation = $this->createOperationDescription($service, $operationDescription['extends'], $baseURL);
        }
        else {
            $operation = new OperationDefinition();
        }

        $operation->setName($operationName);
        $operation->setURL($baseURL);//Do this first, as it can be overwritten
        //TODO use Artax\URI
        
        
        $operation->setFromServiceDescription($operationDescription, $baseURL, $this);

        return $operation;
    }


    /**
     * @param $serviceFilename
     * @return array
     * @throws APIBuilderException
     */
    function parseAndAddServiceFromFile($serviceFilename) {
        $service = require_once($serviceFilename);

        if ($service == false) {
            throw new APIBuilderException("Failed to open service file `$serviceFilename`.");
        }
        if (is_array($service) == false) {
            throw new APIBuilderException("File `$serviceFilename` did not return a service array. Cannot build API from it.");
        }

        $this->parseAndAddService($service);
    }

    /**
     * @param array $service
     * @throws APIBuilderException
     */
    function parseAndAddService(array $service) {

        $baseURL = null;

        if (isset($service["baseUrl"])) {
            $baseURL = $service["baseUrl"];
        }

        foreach ($service["operations"] as $operationName => $operationDescription) {
            if ($this->shouldOperationBeGenerated($operationName)) {
                $operation = $this->createOperationDescription($service, $operationName, $baseURL);
                $this->addMethod($operation->getName(), $operation);
            }
        }
    }


    /**
     * 
     */
    function addAPIParameterAccessMethod() {

        foreach ($this->apiParameters as $apiParameter => $type) {
            $translatedParam = ucfirst($this->translateParameter($apiParameter));
            
            $methodGenerator = new MethodGenerator('get'.$translatedParam);
            $body = 'return $this->'.$apiParameter.';'.PHP_EOL;
            $methodGenerator->setBody($body);
            
            $methodGenerator->setDocBlock("@return $type");
            
            $this->classGenerator->addMethodFromGenerator($methodGenerator);

            $methodGenerator = new MethodGenerator('set'.$translatedParam);
            $body = '$this->'.$apiParameter.' = $value;'.PHP_EOL;
            $parameterParameter = new ParameterGenerator('value');
            $methodGenerator->setParameter($parameterParameter);
            $methodGenerator->setBody($body);
            $this->classGenerator->addMethodFromGenerator($methodGenerator);
        }
    }

    /**
     * 
     */
    private function addProperties(array $nativeProperties) {
        
        if ($this->requiresOauth1 == true) {
            $nativeProperties['oauthService'] = 'ArtaxServiceBuilder\Service\Oauth1';
        }

        $allProperties = [$this->apiParameters, $nativeProperties];

        foreach ($allProperties as $properties) {
            foreach ($properties as $property => $type) {
                $propGenerator = new PropertyGenerator($property);
                $propGenerator->setStandardDocBlock($type);
                $this->classGenerator->addPropertyFromGenerator($propGenerator);
            }
        }
    }

    /**
     * @param $fqcn
     */
    function addUseStatement($fqcn) {
        if (in_array($fqcn, $this->useNames) == false) {
            $this->useNames[] = $fqcn;
        }

        $this->classGenerator->addUse($fqcn);
    }


    /**
     * 
     */
    function addSyncRequestMethod() {
        $methodGenerator = new MethodGenerator('request');
        $methodGenerator->addFlag(AbstractMemberGenerator::FLAG_PRIVATE);
        
$body = <<< 'END'

$client = new \Artax\Client();

return $client->request($request);
//The below should work - but doesn't
//
//
//$response = null;
//$exception = null;
//
//$onError = function(\Exception $exceptionResult) use(&$exception) { $exception = $exceptionResult; };
//$onResponse = function(Response $responseResult) use (&$response)  { $response = $responseResult; };
//
//$reactor = (new ReactorFactory)->select();
//
//$reactor->immediately(function() use ($onResponse, $onError, $request) {
//        $this->client->request($request, $onResponse, $onError);
//    });
//
//while (!($response || $exception)) {
//    $reactor->tick();
//}
//
//if ($response) {
//    return $response;
//}
//
//if ($exception) {
//    /** @var $exception \Exception */
//    throw $exception;
//}
//
//throw new \LogicException("Neither response nor exception were set.");

END;

        $methodGenerator->setBody($body);

        $docBlock = new DocBlockGenerator('Get the last response.');

        $tags[] = new ParamTag('request', ['\Artax\Request']);
        $tags[] = new GenericTag('return', '\Artax\Response');
        $tags[] = new ThrowsTag(['\Exception']);
        $docBlock->setTags($tags);
        $methodGenerator->setDocBlock($docBlock);

        $parameter = new ParameterGenerator('request', 'Artax\Request');
        $methodGenerator->setParameter($parameter);
        $this->classGenerator->addMethodFromGenerator($methodGenerator);
    }
    
    /**
     *
     */
    function generate() {        
        $this->sanityCheck();
        $this->addProperties([]);
        $this->addConstructorMethod();
        $this->addSignMethod();
        
        $this->generateMethods();
        $this->addAPIParameterAccessMethod();

        if (count($this->interfaces)) {
            $this->classGenerator->setImplementedInterfaces($this->interfaces);
        }

        //$this->addSyncRequestMethod();
        $this->generateExceptionClass();
        $this->addExecMethod();
        $this->addExecAsyncMethod();
        $text = $this->classGenerator->generate();
        saveFile($this->outputPath, $this->fqcn, $text);
    }
}