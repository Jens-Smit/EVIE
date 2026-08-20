<?php

namespace App\Dto\Automation;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * ScheduleDto represents a structured schedule for task execution.
 * 
 * This DTO is used to convert natural language schedules into a structured format
 * that can be easily processed by the scheduler.
 * 
 * Supported frequencies:
 * - once: Run once at a specific time
 * - hourly: Run every hour
 * - daily: Run every day at a specific time
 * - weekly: Run every week on a specific day at a specific time
 * - monthly: Run every month on a specific day at a specific time
 * - cron: Run according to a cron expression
 */
class ScheduleDto
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: 'getValidFrequencies')]
    public string $frequency;

    #[Assert\NotBlank]
    #[Assert\Timezone]
    public string $timezone = 'UTC';

    // For 'once' frequency
    #[Assert\DateTime(format: 'Y-m-d\TH:i:sP')]
    public ?string $time = null;

    // For 'daily', 'weekly', 'monthly' frequencies
    #[Assert\Time(format: 'H:i')]
    public ?string $dailyTime = null;

    // For 'weekly' frequency
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Choice(callback: 'getValidDays')]
    public ?string $dayOfWeek = null;

    // For 'monthly' frequency
    #[Assert\Range(min: 1, max: 31)]
    public ?int $dayOfMonth = null;

    // For 'cron' frequency
    #[Assert\NotBlank(allowNull: true)]
    #[Assert\Regex('/^[\\*\\d,\\-\\s]+[\\*\\d,\\-\\s]+[\\*\\d,\\-\\s]+[\\*\\d,\\-\\s]+[\\*\\d,\\-\\s]+$/', message: 'Invalid cron expression')]
    public ?string $cronExpression = null;

    // For 'hourly' frequency
    #[Assert\Range(min: 0, max: 59)]
    public ?int $minute = null;

    /**
     * Get valid frequencies.
     * 
     * @return array
     */
    public static function getValidFrequencies(): array
    {
        return ['once', 'hourly', 'daily', 'weekly', 'monthly', 'cron'];
    }

    /**
     * Get valid days of the week.
     * 
     * @return array
     */
    public static function getValidDays(): array
    {
        return ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    }

    /**
     * Create a ScheduleDto from an array.
     * 
     * @param array $data The schedule data
     * @return self
     */
    public static function createFromArray(array $data): self
    {
        $dto = new self();
        
        $dto->frequency = $data['frequency'] ?? 'once';
        $dto->timezone = $data['timezone'] ?? 'UTC';
        $dto->time = $data['time'] ?? null;
        $dto->dailyTime = $data['dailyTime'] ?? $data['time'] ?? null;
        $dto->dayOfWeek = $data['dayOfWeek'] ?? $data['day'] ?? null;
        $dto->dayOfMonth = $data['dayOfMonth'] ?? $data['day'] ?? null;
        $dto->cronExpression = $data['cronExpression'] ?? $data['cron'] ?? null;
        $dto->minute = $data['minute'] ?? null;

        return $dto;
    }

    /**
     * Convert the DTO to an array.
     * 
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'frequency' => $this->frequency,
            'timezone' => $this->timezone,
        ];

        switch ($this->frequency) {
            case 'once':
                $data['time'] = $this->time;
                break;

            case 'hourly':
                $data['minute'] = $this->minute;
                break;

            case 'daily':
                $data['time'] = $this->dailyTime;
                break;

            case 'weekly':
                $data['day'] = $this->dayOfWeek;
                $data['time'] = $this->dailyTime;
                break;

            case 'monthly':
                $data['day'] = $this->dayOfMonth;
                $data['time'] = $this->dailyTime;
                break;

            case 'cron':
                $data['cron'] = $this->cronExpression;
                break;
        }

        return $data;
    }

    /**
     * Convert the DTO to a JSON string.
     * 
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create a ScheduleDto from a JSON string.
     * 
     * @param string $json The JSON string
     * @return self
     */
    public static function createFromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::createFromArray($data);
    }

    /**
     * Validate the DTO.
     * 
     * @return array Validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Validate frequency
        if (!in_array($this->frequency, self::getValidFrequencies(), true)) {
            $errors['frequency'] = 'Invalid frequency';
        }

        // Validate timezone
        try {
            new \DateTimeZone($this->timezone);
        } catch (\Exception $e) {
            $errors['timezone'] = 'Invalid timezone';
        }

        // Validate frequency-specific fields
        switch ($this->frequency) {
            case 'once':
                if ($this->time === null) {
                    $errors['time'] = 'Time is required for once frequency';
                } elseif (!$this->isValidDateTime($this->time)) {
                    $errors['time'] = 'Invalid datetime format';
                }
                break;

            case 'hourly':
                if ($this->minute !== null && ($this->minute < 0 || $this->minute > 59)) {
                    $errors['minute'] = 'Minute must be between 0 and 59';
                }
                break;

            case 'daily':
                if ($this->dailyTime === null) {
                    $errors['time'] = 'Time is required for daily frequency';
                } elseif (!$this->isValidTime($this->dailyTime)) {
                    $errors['time'] = 'Invalid time format';
                }
                break;

            case 'weekly':
                if (!in_array($this->dayOfWeek, self::getValidDays(), true)) {
                    $errors['day'] = 'Invalid day of week';
                }
                if ($this->dailyTime === null) {
                    $errors['time'] = 'Time is required for weekly frequency';
                } elseif (!$this->isValidTime($this->dailyTime)) {
                    $errors['time'] = 'Invalid time format';
                }
                break;

            case 'monthly':
                if ($this->dayOfMonth !== null && ($this->dayOfMonth < 1 || $this->dayOfMonth > 31)) {
                    $errors['day'] = 'Day of month must be between 1 and 31';
                }
                if ($this->dailyTime === null) {
                    $errors['time'] = 'Time is required for monthly frequency';
                } elseif (!$this->isValidTime($this->dailyTime)) {
                    $errors['time'] = 'Invalid time format';
                }
                break;

            case 'cron':
                if ($this->cronExpression === null) {
                    $errors['cron'] = 'Cron expression is required for cron frequency';
                } elseif (!$this->isValidCronExpression($this->cronExpression)) {
                    $errors['cron'] = 'Invalid cron expression';
                }
                break;
        }

        return $errors;
    }

    /**
     * Check if a string is a valid datetime.
     * 
     * @param string $datetime The datetime string
     * @return bool
     */
    private function isValidDateTime(string $datetime): bool
    {
        try {
            new \DateTimeImmutable($datetime);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a string is a valid time.
     * 
     * @param string $time The time string
     * @return bool
     */
    private function isValidTime(string $time): bool
    {
        return preg_match('/^\d{2}:\d{2}$/', $time) === 1;
    }

    /**
     * Check if a string is a valid cron expression.
     * 
     * @param string $expression The cron expression
     * @return bool
     */
    private function isValidCronExpression(string $expression): bool
    {
        // Basic validation (5 parts separated by spaces)
        $parts = explode(' ', $expression);
        if (count($parts) !== 5) {
            return false;
        }

        // Validate each part
        foreach ($parts as $part) {
            if (!preg_match('/^[\\*\\d,\\-\\s\\/]+$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a human-readable description of the schedule.
     * 
     * @return string
     */
    public function getDescription(): string
    {
        switch ($this->frequency) {
            case 'once':
                return sprintf(
                    'Once at %s (%s)',
                    $this->time ?? '?',
                    $this->timezone
                );

            case 'hourly':
                $minute = $this->minute ?? '0';
                return sprintf(
                    'Every hour at minute %s (%s)',
                    $minute,
                    $this->timezone
                );

            case 'daily':
                return sprintf(
                    'Daily at %s (%s)',
                    $this->dailyTime ?? '?',
                    $this->timezone
                );

            case 'weekly':
                return sprintf(
                    'Every %s at %s (%s)',
                    $this->dayOfWeek ?? '?',
                    $this->dailyTime ?? '?',
                    $this->timezone
                );

            case 'monthly':
                return sprintf(
                    'Monthly on day %d at %s (%s)',
                    $this->dayOfMonth ?? '?',
                    $this->dailyTime ?? '?',
                    $this->timezone
                );

            case 'cron':
                return sprintf(
                    'Cron: %s (%s)',
                    $this->cronExpression ?? '?',
                    $this->timezone
                );

            default:
                return 'Unknown schedule';
        }
    }

    /**
     * Convert the DTO to a format suitable for the ScheduledTask entity.
     * 
     * @return array
     */
    public function toTaskSchedule(): array
    {
        $schedule = [
            'frequency' => $this->frequency,
            'timezone' => $this->timezone,
        ];

        switch ($this->frequency) {
            case 'once':
                $schedule['time'] = $this->time;
                break;

            case 'hourly':
                $schedule['minute'] = $this->minute;
                break;

            case 'daily':
                $schedule['time'] = $this->dailyTime;
                break;

            case 'weekly':
                $schedule['day'] = $this->dayOfWeek;
                $schedule['time'] = $this->dailyTime;
                break;

            case 'monthly':
                $schedule['day'] = $this->dayOfMonth;
                $schedule['time'] = $this->dailyTime;
                break;

            case 'cron':
                $schedule['expression'] = $this->cronExpression;
                break;
        }

        return $schedule;
    }

    /**
     * Create a ScheduleDto from a ScheduledTask entity.
     * 
     * @param array $schedule The task schedule array
     * @return self
     */
    public static function createFromTaskSchedule(array $schedule): self
    {
        $dto = new self();
        
        $dto->frequency = $schedule['frequency'] ?? 'once';
        $dto->timezone = $schedule['timezone'] ?? 'UTC';
        $dto->time = $schedule['time'] ?? null;
        $dto->dailyTime = $schedule['time'] ?? null;
        $dto->dayOfWeek = $schedule['day'] ?? null;
        $dto->dayOfMonth = isset($schedule['day']) && is_int($schedule['day']) ? $schedule['day'] : null;
        $dto->cronExpression = $schedule['expression'] ?? null;
        $dto->minute = $schedule['minute'] ?? null;

        return $dto;
    }
}
