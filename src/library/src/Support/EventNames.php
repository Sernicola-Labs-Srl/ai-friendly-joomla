<?php

namespace Sernicola\Library\AiFriendly\Support;

defined('_JEXEC') or die;

final class EventNames
{
    public const COLLECT_PROVIDERS = 'onAiFriendlyCollectProviders';
    public const COLLECT_RULES = 'onAiFriendlyCollectRules';
    public const BUILD_LLMS = 'onAiFriendlyBuildLlms';
    public const CAN_EXPOSE = 'onAiFriendlyCanExpose';
    public const PREPARE_MARKDOWN = 'onAiFriendlyPrepareMarkdown';
    public const BUILD_SCHEMA = 'onAiFriendlyBuildSchema';
    public const RUNTIME_ERROR = 'onAiFriendlyRuntimeError';
}

