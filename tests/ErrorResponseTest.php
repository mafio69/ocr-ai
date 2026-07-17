<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Error\ErrorResponse;

class ErrorResponseTest extends TestCase
{
    public function testGoogleApiErrorReturns502(): void
    {
        $response = new ErrorResponse(
            userMessage: 'Google API error',
            internalMessage: 'Technical details',
            code: 'GOOGLE_API_ERROR'
        );
        
        $this->assertSame(502, $response->getHttpStatusCode());
    }

    public function testOcrErrorReturns422(): void
    {
        $response = new ErrorResponse(
            userMessage: 'OCR error',
            internalMessage: 'Technical details',
            code: 'OCR_ERROR'
        );
        
        $this->assertSame(422, $response->getHttpStatusCode());
    }

    public function testFileNotFoundReturns404(): void
    {
        $response = new ErrorResponse(
            userMessage: 'File not found',
            internalMessage: 'Technical details',
            code: 'FILE_NOT_FOUND'
        );
        
        $this->assertSame(404, $response->getHttpStatusCode());
    }

    public function testUnauthorizedReturns401(): void
    {
        $response = new ErrorResponse(
            userMessage: 'Unauthorized',
            internalMessage: 'Technical details',
            code: 'UNAUTHORIZED'
        );
        
        $this->assertSame(401, $response->getHttpStatusCode());
    }

    public function testUnknownCodeReturns500(): void
    {
        $response = new ErrorResponse(
            userMessage: 'Unknown error',
            internalMessage: 'Technical details',
            code: 'UNKNOWN_ERROR'
        );
        
        $this->assertSame(500, $response->getHttpStatusCode());
    }

    public function testDefaultCodeReturns500(): void
    {
        $response = new ErrorResponse(
            userMessage: 'Error',
            internalMessage: 'Technical details'
        );
        
        $this->assertSame(500, $response->getHttpStatusCode());
    }

    public function testGetUserMessage(): void
    {
        $response = new ErrorResponse(
            userMessage: 'User friendly message',
            internalMessage: 'Technical details',
            code: 'OCR_ERROR'
        );
        
        $this->assertSame('User friendly message', $response->getUserMessage());
    }

    public function testGetDebugInfo(): void
    {
        $response = new ErrorResponse(
            userMessage: 'User message',
            internalMessage: 'Internal message',
            context: ['key' => 'value'],
            code: 'OCR_ERROR'
        );
        
        $debugInfo = $response->getDebugInfo();
        
        $this->assertSame('User message', $debugInfo['userMessage']);
        $this->assertSame('Internal message', $debugInfo['internalMessage']);
        $this->assertSame('OCR_ERROR', $debugInfo['code']);
        $this->assertSame(['key' => 'value'], $debugInfo['context']);
    }

    public function testToJsonInDevelopmentMode(): void
    {
        $response = new ErrorResponse(
            userMessage: 'User message',
            internalMessage: 'Internal message',
            context: ['key' => 'value'],
            code: 'OCR_ERROR',
            isDevelopment: true
        );
        
        $json = $response->toJson();
        $data = json_decode($json, true);
        
        $this->assertFalse($data['success']);
        $this->assertSame('User message', $data['error']['message']);
        $this->assertSame('OCR_ERROR', $data['error']['code']);
        $this->assertSame('Internal message', $data['error']['internal']);
        $this->assertSame(['key' => 'value'], $data['error']['context']);
    }

    public function testToJsonInProductionMode(): void
    {
        $response = new ErrorResponse(
            userMessage: 'User message',
            internalMessage: 'Internal message',
            context: ['key' => 'value'],
            code: 'OCR_ERROR',
            isDevelopment: false
        );
        
        $json = $response->toJson();
        $data = json_decode($json, true);
        
        $this->assertFalse($data['success']);
        $this->assertSame('User message', $data['error']['message']);
        $this->assertSame('OCR_ERROR', $data['error']['code']);
        $this->assertArrayNotHasKey('internal', $data['error']);
        $this->assertArrayNotHasKey('context', $data['error']);
    }
}
