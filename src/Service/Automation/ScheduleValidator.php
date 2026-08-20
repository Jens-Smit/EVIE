<?php

namespace App\Service\Automation;

use App\Dto\Automation\ScheduleDto;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * ScheduleValidator validates and normalizes schedule definitions.
 * 
 * This service provides:
 * - Validation of ScheduleDto objects
 * - Normalization of schedule data
 * - Conversion between different schedule formats
 * - Timezone handling and normalization
 */
class ScheduleValidator
{
    private const array DEFAULT_TIMEZONE = 'UTC';
    private const array VALID_FREQUENCIES = ['once', 'hourly', 'daily', 'weekly', 'monthly', 'cron'];
    private const array VALID_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        private ValidatorInterface $validator,
        private string $defaultTimezone = self::DEFAULT_TIMEZONE
    ) {
    }

    /**
     * Validate a ScheduleDto.
     * 
     * @param ScheduleDto $dto The DTO to validate
     * @return array Validation errors
     */
    public function validate(ScheduleDto $dto): array
    {
        $errors = [];

        // Use Symfony Validator
        $violations = $this->validator->validate($dto);
        
        foreach ($violations as $violation) {
            $propertyPath = $violation->getPropertyPath();
            $errors[$propertyPath] = $violation->getMessage();
        }

        // Additional custom validation
        $customErrors = $dto->validate();
        $errors = array_merge($errors, $customErrors);

        return $errors;
    }

    /**
     * Validate and normalize a schedule array.
     * 
     * @param array $schedule The schedule array
     * @param string|null $timezone The timezone (overrides default)
     * @return array Normalized schedule
     * @throws \InvalidArgumentException If schedule is invalid
     */
    public function validateAndNormalize(array $schedule, ?string $timezone = null): array
    {
        $timezone = $timezone ?? $this->defaultTimezone;

        // Create DTO from array
        $dto = ScheduleDto::createFromArray($schedule);
        
        // Set timezone if provided
        if ($timezone !== null) {
            $dto->timezone = $timezone;
        }

        // Validate
        $errors = $this->validate($dto);
        
        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                'Invalid schedule: ' . implode(', ', $errors)
            );
        }

        // Normalize and return as array
        return $this->normalizeSchedule($dto);
    }

    /**
     * Normalize a schedule to a consistent format.
     * 
     * @param ScheduleDto $dto The schedule DTO
     * @return array Normalized schedule
     */
    public function normalizeSchedule(ScheduleDto $dto): array
    {
        $schedule = [
            'frequency' => $dto->frequency,
            'timezone' => $dto->timezone,
        ];

        switch ($dto->frequency) {
            case 'once':
                $schedule['time'] = $this->normalizeDateTime($dto->time, $dto->timezone);
                break;

            case 'hourly':
                $schedule['minute'] = $dto->minute ?? 0;
                break;

            case 'daily':
                $schedule['time'] = $this->normalizeTime($dto->dailyTime);
                break;

            case 'weekly':
                $schedule['day'] = $this->normalizeDay($dto->dayOfWeek);
                $schedule['time'] = $this->normalizeTime($dto->dailyTime);
                break;

            case 'monthly':
                $schedule['day'] = $dto->dayOfMonth;
                $schedule['time'] = $this->normalizeTime($dto->dailyTime);
                break;

            case 'cron':
                $schedule['expression'] = $this->normalizeCronExpression($dto->cronExpression);
                break;
        }

        return $schedule;
    }

    /**
     * Normalize a datetime string.
     * 
     * @param string|null $datetime The datetime string
     * @param string $timezone The timezone
     * @return string|null Normalized datetime
     */
    private function normalizeDateTime(?string $datetime, string $timezone): ?string
    {
        if ($datetime === null) {
            return null;
        }

        try {
            $dateTime = new \DateTimeImmutable($datetime, new \DateTimeZone($timezone));
            return $dateTime->format('Y-m-d\TH:i:sP');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normalize a time string.
     * 
     * @param string|null $time The time string
     * @return string|null Normalized time (HH:MM)
     */
    private function normalizeTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        // Parse time string
        $parts = explode(':', $time);
        if (count($parts) !== 2) {
            return null;
        }

        $hour = (int)$parts[0];
        $minute = (int)$parts[1];

        // Validate
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Normalize a day of week string.
     * 
     * @param string|null $day The day string
     * @return string|null Normalized day (lowercase)
     */
    private function normalizeDay(?string $day): ?string
    {
        if ($day === null) {
            return null;
        }

        $day = strtolower($day);
        
        if (!in_array($day, self::VALID_DAYS, true)) {
            return null;
        }

        return $day;
    }

    /**
     * Normalize a cron expression.
     * 
     * @param string|null $expression The cron expression
     * @return string|null Normalized cron expression
     */
    private function normalizeCronExpression(?string $expression): ?string
    {
        if ($expression === null) {
            return null;
        }

        // Basic validation
        $parts = preg_split('/\\s+/', trim($expression));
        if (count($parts) !== 5) {
            return null;
        }

        return $expression;
    }

    /**
     * Convert a natural language schedule to a normalized schedule array.
     * 
     * @param string $text The natural language text
     * @param string|null $timezone The timezone
     * @return array Normalized schedule
     * @throws \InvalidArgumentException If schedule cannot be parsed
     */
    public function parseNaturalLanguage(string $text, ?string $timezone = null): array
    {
        $timezone = $timezone ?? $this->defaultTimezone;
        
        // Parse natural language
        $schedule = $this->parseNaturalLanguageInternal($text, $timezone);
        
        // Validate and normalize
        return $this->validateAndNormalize($schedule, $timezone);
    }

    /**
     * Internal method to parse natural language.
     * 
     * @param string $text The natural language text
     * @param string $timezone The timezone
     * @return array Parsed schedule
     */
    private function parseNaturalLanguageInternal(string $text, string $timezone): array
    {
        $text = strtolower(trim($text));

        // Remove common prefixes
        $text = preg_replace('/^(every|each|at|on|in|run|execute)\s+/i', '', $text);

        $schedule = [
            'frequency' => 'once',
            'timezone' => $timezone,
        ];

        // Parse frequency
        if (preg_match('/(hourly|every hour|each hour|hour)/i', $text)) {
            $schedule['frequency'] = 'hourly';
        } elseif (preg_match('/(daily|every day|each day|day)/i', $text)) {
            $schedule['frequency'] = 'daily';
        } elseif (preg_match('/(weekly|every week|each week|week)/i', $text)) {
            $schedule['frequency'] = 'weekly';
        } elseif (preg_match('/(monthly|every month|each month|month)/i', $text)) {
            $schedule['frequency'] = 'monthly';
        } elseif (preg_match('/(cron|crontab)/i', $text)) {
            $schedule['frequency'] = 'cron';
        }

        // Parse day of week for weekly
        if ($schedule['frequency'] === 'weekly') {
            foreach (self::VALID_DAYS as $day) {
                if (preg_match('/\b' . $day . '\b/i', $text)) {
                    $schedule['day'] = $day;
                    break;
                }
            }
        }

        // Parse day of month for monthly
        if ($schedule['frequency'] === 'monthly') {
            if (preg_match('/(\d{1,2})(st|nd|rd|th)/', $text, $matches)) {
                $schedule['day'] = (int)$matches[1];
            } elseif (preg_match('/(\d{1,2})\s*(of the month|of month)/i', $text, $matches)) {
                $schedule['day'] = (int)$matches[1];
            }
        }

        // Parse time
        if (preg_match('/(\d{1,2}:\d{2})/', $text, $matches)) {
            $schedule['time'] = $matches[1];
        } elseif (preg_match('/(\d{1,2})\s*(am|pm)/i', $text, $matches)) {
            $hour = (int)$matches[1];
            $period = strtolower($matches[2]);
            
            if ($period === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }
            
            $schedule['time'] = sprintf('%02d:00', $hour);
        } elseif (preg_match('/(\d{1,2})\s*(o\'?clock)/i', $text, $matches)) {
            $hour = (int)$matches[1];
            $schedule['time'] = sprintf('%02d:00', $hour);
        }

        // Parse minute for hourly
        if ($schedule['frequency'] === 'hourly') {
            if (preg_match('/(at|on)\s+(\d{1,2})\s*(minute|min)/i', $text, $matches)) {
                $schedule['minute'] = (int)$matches[2];
            } elseif (preg_match('/:(\d{2})/', $text, $matches)) {
                $schedule['minute'] = (int)$matches[1];
            }
        }

        // Parse cron expression
        if ($schedule['frequency'] === 'cron') {
            if (preg_match('/"([^"]+)"/', $text, $matches)) {
                $schedule['expression'] = $matches[1];
            } elseif (preg_match('/([^\\s]+\\s+[^\\s]+\\s+[^\\s]+\\s+[^\\s]+\\s+[^\\s]+)/', $text, $matches)) {
                $schedule['expression'] = $matches[0];
            }
        }

        // Parse specific time for once
        if ($schedule['frequency'] === 'once') {
            if (preg_match('/(at|on)\s+([\\d:\\s-]+)/i', $text, $matches)) {
                $datetime = trim($matches[2]);
                
                // Try to parse as datetime
                try {
                    $dateTime = new \DateTimeImmutable($datetime, new \DateTimeZone($timezone));
                    $schedule['time'] = $dateTime->format('Y-m-d\TH:i:sP');
                } catch (\Exception $e) {
                    // Try to parse as time only
                    if (preg_match('/(\d{1,2}:\d{2})/', $datetime, $timeMatches)) {
                        $schedule['time'] = $this->normalizeTime($timeMatches[1]);
                    }
                }
            }
        }

        return $schedule;
    }

    /**
     * Create a ScheduleDto from natural language.
     * 
     * @param string $text The natural language text
     * @param string|null $timezone The timezone
     * @return ScheduleDto
     * @throws \InvalidArgumentException If schedule cannot be parsed
     */
    public function createScheduleDtoFromNaturalLanguage(string $text, ?string $timezone = null): ScheduleDto
    {
        $schedule = $this->parseNaturalLanguage($text, $timezone);
        return ScheduleDto::createFromArray($schedule);
    }

    /**
     * Get the default timezone.
     * 
     * @return string
     */
    public function getDefaultTimezone(): string
    {
        return $this->defaultTimezone;
    }

    /**
     * Set the default timezone.
     * 
     * @param string $timezone The timezone
     * @return self
     */
    public function setDefaultTimezone(string $timezone): self
    {
        $this->defaultTimezone = $timezone;
        return $this;
    }

    /**
     * Get valid frequencies.
     * 
     * @return array
     */
    public function getValidFrequencies(): array
    {
        return self::VALID_FREQUENCIES;
    }

    /**
     * Get valid days of the week.
     * 
     * @return array
     */
    public function getValidDays(): array
    {
        return self::VALID_DAYS;
    }

    /**
     * Check if a frequency is valid.
     * 
     * @param string $frequency The frequency
     * @return bool
     */
    public function isValidFrequency(string $frequency): bool
    {
        return in_array($frequency, self::VALID_FREQUENCIES, true);
    }

    /**
     * Check if a day is valid.
     * 
     * @param string $day The day
     * @return bool
     */
    public function isValidDay(string $day): bool
    {
        return in_array(strtolower($day), self::VALID_DAYS, true);
    }

    /**
     * Check if a timezone is valid.
     * 
     * @param string $timezone The timezone
     * @return bool
     */
    public function isValidTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
