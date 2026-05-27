<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Форматирование данных для отправки в Telegram (Markdown)
 */
class TelegramFormatter
{
    /**
     * Максимальная длина сообщения Telegram (ограничение API)
     */
    private const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Форматирование рецепта в Markdown для Telegram
     *
     * @param array $recipe Данные рецепта из API
     * @return string Отформатированное сообщение
     */
    public function formatRecipe(array $recipe): string
    {
        $title = $recipe['title'] ?? 'Без названия';
        $description = $recipe['description'] ?? '';
        $ingredients = $recipe['ingredients_list'] ?? [];
        $steps = $recipe['steps'] ?? [];
        $cookingTime = $recipe['cooking_time'] ?? null;
        $difficulty = $recipe['difficulty'] ?? null;

        $message = "*🍳 {$title}*\n\n";

        if ($description) {
            $message .= "_{$description}_\n\n";
        }

        if ($cookingTime || $difficulty) {
            $message .= "⏱ *Информация:*\n";
            if ($cookingTime) {
                $message .= "• Время: {$cookingTime} мин\n";
            }
            if ($difficulty) {
                $message .= "• Сложность: {$this->translateDifficulty($difficulty)}\n";
            }
            $message .= "\n";
        }

        // Ингредиенты
        $message .= "*🥕 Ингредиенты:*\n";
        foreach ($ingredients as $ingredient) {
            $item = is_array($ingredient) 
                ? "{$ingredient['name']} - {$ingredient['amount']} {$ingredient['unit']}"
                : $ingredient;
            $message .= "• {$item}\n";
        }
        $message .= "\n";

        // Шаги приготовления
        $message .= "*👨‍🍳 Приготовление:*\n";
        foreach ($steps as $index => $step) {
            $message .= ($index + 1) . ". {$step}\n";
        }

        // Проверка длины сообщения
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return $this->truncateMessage($message);
        }

        return $message;
    }

    /**
     * Форматирование списка рецептов для истории
     *
     * @param array $recipes Список рецептов
     * @param int $currentPage Текущая страница
     * @param int $totalPages Всего страниц
     * @return string Отформатированное сообщение
     */
    public function formatRecipeHistory(
        array $recipes,
        int $currentPage,
        int $totalPages
    ): string {
        if (empty($recipes)) {
            return "*📚 История рецептов*\n\nУ вас пока нет сохраненных рецептов.\nСгенерируйте первый рецепт!";
        }

        $message = "*📚 История рецептов*\n\n";

        foreach ($recipes as $index => $recipe) {
            $num = (($currentPage - 1) * count($recipes)) + $index + 1;
            $title = $recipe['title'] ?? 'Без названия';
            $preview = $recipe['preview_text'] ?? '';
            $createdAt = isset($recipe['created_at']) 
                ? date('d.m.Y H:i', strtotime($recipe['created_at']))
                : '';

            $message .= "*{$num}. {$title}*\n";
            if ($preview) {
                $message .= "_{$preview}_\n";
            }
            if ($createdAt) {
                $message .= "📅 {$createdAt}\n";
            }
            $message .= "\n";
        }

        $message .= "Страница {$currentPage} из {$totalPages}";

        return $message;
    }

    /**
     * Форматирование приветственного сообщения
     *
     * @param string $userName Имя пользователя
     * @return string Отформатированное сообщение
     */
    public function formatWelcome(string $userName): string
    {
        return "*👋 Привет, {$userName}!*\n\n" .
            "Я — ваш персональный AI-шеф повар! 🍳\n\n" .
            "*Что я умею:*\n" .
            "• Генерировать рецепты из ваших ингредиентов\n" .
            "• Учитывать ваши предпочтения (время, сложность, диета)\n" .
            "• Хранить историю ваших рецептов\n\n" .
            "*Как начать:*\n" .
            "Просто отправьте мне список продуктов, которые есть у вас в холодильнике!\n\n" .
            "Например: _картофель, курица, лук, морковь_\n\n" .
            "Или используйте команды:\n" .
            "/help - помощь\n" .
            "/history - история рецептов";
    }

    /**
     * Форматирование сообщения о помощи
     *
     * @return string Отформатированное сообщение
     */
    public function formatHelp(): string
    {
        return "*❓ Помощь*\n\n" .
            "*Доступные команды:*\n" .
            "/start - начать диалог\n" .
            "/help - показать эту справку\n" .
            "/history - посмотреть историю рецептов\n" .
            "/cancel - отменить текущий заказ\n\n" .
            "*Как это работает:*\n" .
            "1. Отправьте список ингредиентов\n" .
            "2. Укажите предпочтения (по желанию)\n" .
            "3. Получите готовый рецепт!\n\n" .
            "*Пример:*\n" .
            "_картофель, курица, сыр, помидоры_\n" .
            "Время: 30 минут, легкая сложность";
    }

    /**
     * Форматирование сообщения с вопросом о параметрах
     *
     * @param array $ingredients Список ингредиентов
     * @return string Отформатированное сообщение
     */
    public function formatParametersQuestion(array $ingredients): string
    {
        $ingredientsList = implode(', ', $ingredients);

        return "*✅ Ингредиенты приняты:*\n_{$ingredientsList}_\n\n" .
            "*Теперь уточните параметры (по желанию):*\n\n" .
            "⏱ *Время приготовления:*\n" .
            "• быстро (до 15 мин)\n" .
            "• средне (15-30 мин)\n" .
            "• долго (30+ мин)\n\n" .
            "📊 *Сложность:*\n" .
            "• легкая\n" .
            "• средняя\n" .
            "• высокая\n\n" .
            "🥗 *Диетические ограничения:*\n" .
            "• без глютена\n" .
            "• вегетарианское\n" .
            "• низкокалорийное\n\n" .
            "Или напишите *\"готовь\"* чтобы использовать настройки по умолчанию\n" .
            "Или *\"назад\"* чтобы изменить ингредиенты";
    }

    /**
     * Перевод уровня сложности на русский
     *
     * @param string $difficulty Уровень сложности
     * @return string Переведенное значение
     */
    private function translateDifficulty(string $difficulty): string
    {
        return match(strtolower($difficulty)) {
            'easy' => 'Легкая',
            'medium' => 'Средняя',
            'hard' => 'Высокая',
            default => ucfirst($difficulty),
        };
    }

    /**
     * Обрезка сообщения до максимальной длины
     *
     * @param string $message Исходное сообщение
     * @return string Обрезанное сообщение
     */
    private function truncateMessage(string $message): string
    {
        $truncated = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 100);
        
        return $truncated . "\n\n... (сообщение обрезано)";
    }
}
