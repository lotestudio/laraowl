<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class AlertService
{
    protected IntegrationService $integrationService;

    public function __construct(IntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    /**
     * Notify about a new issue (exception).
     */
    public function notifyNewIssue(Issue $issue)
    {
        $project = $issue->project;
        $rules = $project->alertRules()
            ->where('event_type', 'new_exception')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '🚨 New Exception: '.$issue->title;
        $message = $issue->message;
        $url = config('app.url')."/{$project->team_slug}/{$project->slug}/issues/{$issue->id}";
        $fields = [
            'Project' => $project->name,
            'Type' => $issue->type,
            'Priority' => strtoupper($issue->priority),
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about slow performance violation.
     */
    public function notifySlowPerformance(Issue $issue)
    {
        $project = $issue->project;
        $rules = $project->alertRules()
            ->where('event_type', 'high_latency')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '⏱️ High Latency: '.$issue->title;
        $message = $issue->message;
        $url = config('app.url')."/{$project->team_slug}/{$project->slug}/issues/{$issue->id}";
        $fields = [
            'Project' => $project->name,
            'Priority' => strtoupper($issue->priority),
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about uptime down.
     */
    public function notifyUptimeDown(Project $project, int $statusCode, ?string $error = null)
    {
        $rules = $project->alertRules()
            ->where('event_type', 'uptime_down')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '🚨 Uptime Alert: Site is DOWN!';
        $message = "The site returned a {$statusCode} status code.".($error ? "\nError: {$error}" : '');
        $url = config('app.url')."/{$project->team_slug}/{$project->slug}";
        $fields = [
            'Project' => $project->name,
            'URL' => $project->url,
            'Status' => $statusCode,
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about heartbeat failure.
     */
    public function notifyHeartbeatFailed($heartbeat)
    {
        $project = $heartbeat->project;
        $rules = $project->alertRules()
            ->where('event_type', 'heartbeat_failed')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '💓 Heartbeat Failure: '.$heartbeat->name;
        $message = "The heartbeat '{$heartbeat->name}' has stopped checking in.";
        $url = config('app.url')."/{$project->team_slug}/{$project->slug}";
        $fields = [
            'Project' => $project->name,
            'Last Seen' => $heartbeat->last_seen_at ? $heartbeat->last_seen_at->diffForHumans() : 'Never',
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Notify about an error spike.
     */
    public function notifyErrorSpike(Project $project, int $count, int $windowMinutes)
    {
        $rules = $project->alertRules()
            ->where('event_type', 'error_spike')
            ->where('is_enabled', true)
            ->with('integrations')
            ->get();

        $title = '🔥 Error Spike Detected!';
        $message = "Detected {$count} errors in the last {$windowMinutes} minutes.";
        $url = config('app.url')."/{$project->team_slug}/{$project->slug}";
        $fields = [
            'Project' => $project->name,
            'Spike Count' => $count,
            'Time Window' => "{$windowMinutes}m",
        ];

        foreach ($rules as $rule) {
            $this->dispatchAlert($rule, $title, $message, $fields, $url);
        }
    }

    /**
     * Dispatch alert to all integrations of a rule.
     */
    protected function dispatchAlert(AlertRule $rule, string $title, string $message, array $fields = [], ?string $url = null)
    {
        $settings = $rule->settings ?? [];
        $throttlePeriod = $settings['throttle_period'] ?? 3600;

        $errorHash = md5($title.$message);
        $cacheKey = "alert_rule_{$rule->id}_{$errorHash}";

        $lastSentKey = "{$cacheKey}_last_sent";
        if ($throttlePeriod > 0 && Cache::has($lastSentKey)) {
            return;
        }

        if ($throttlePeriod > 0) {
            Cache::put($lastSentKey, true, now()->addSeconds($throttlePeriod));
        }

        foreach ($rule->integrations as $integration) {
            if (! $integration->is_enabled) {
                continue;
            }
            $this->integrationService->send($integration, $title, $message, $fields, $url);
        }
    }
}
