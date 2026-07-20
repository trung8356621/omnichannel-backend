<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\BusinessHook\Enums;

/**
 * Core action codes. Handler resolve qua AutomationActionRegistry, không lưu class trong DB.
 */
enum AutomationActionCode: string
{
    case WordpressArticleSync = 'wordpress.article.sync';
    case ArticleGenerateContent = 'article.generate_content';
    case ArticleRunSeoAnalysis = 'article.run_seo_analysis';
    case WebhookSend = 'webhook.send';
    case NotificationSend = 'notification.send';
    case Delay = 'delay';
    case AutomationDispatchEvent = 'automation.dispatch_event';
}
