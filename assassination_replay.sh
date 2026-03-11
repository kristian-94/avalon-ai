#!/bin/bash

DUMP_FILE="storage/app/ai-dumps/game49_Max_assassination_2026-03-08_11-25-58.json"
MODEL="${1:-claude-sonnet-4-6}"

if [[ "$MODEL" == openai/gpt-oss* ]] || [[ "$MODEL" == llama* ]] || [[ "$MODEL" == deepseek* ]] || [[ "$MODEL" == mixtral* ]] || [[ "$MODEL" == meta-llama* ]] || [[ "$MODEL" == moonshotai* ]] || [[ "$MODEL" == qwen* ]]; then
    API_KEY=$(grep GROQ_API_KEY .env | cut -d= -f2)
    ENDPOINT="https://api.groq.com/openai/v1/chat/completions"
    USE_ANTHROPIC=false
elif [[ "$MODEL" == claude* ]]; then
    API_KEY=$(grep ANTHROPIC_API_KEY .env | cut -d= -f2)
    ENDPOINT="https://api.anthropic.com/v1/messages"
    USE_ANTHROPIC=true
else
    API_KEY=$(grep OPEN_AI_API_KEY .env | cut -d= -f2)
    ENDPOINT="https://api.openai.com/v1/chat/completions"
    USE_ANTHROPIC=false
fi

if [ "$USE_ANTHROPIC" = true ]; then
    python3 -c "
import json
with open('$DUMP_FILE') as f:
    d = json.load(f)

messages = d['messages']
system_messages = [m for m in messages if m['role'] == 'system']
non_system = [m for m in messages if m['role'] != 'system']

system_text = '\n\n'.join(m['content'] for m in system_messages)
system_text += '\n\nRespond with JSON. You MUST use this exact field order: reasoning first, then assassination_target, then message. Think through your reasoning before committing to a target.'

payload = {
    'model': '$MODEL',
    'max_tokens': 1024,
    'system': system_text,
    'messages': non_system,
}
print(json.dumps(payload))
" | curl "$ENDPOINT" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -d @-
else
    python3 -c "
import json
with open('$DUMP_FILE') as f:
    d = json.load(f)
payload = {
    'model': '$MODEL',
    'messages': d['messages'] + [{
        'role': 'system',
        'content': 'Respond with JSON. You MUST use this exact field order: reasoning first, then assassination_target, then message. Think through your reasoning before committing to a target.'
    }],
    'response_format': {'type': 'json_object'}
}
print(json.dumps(payload))
" | curl "$ENDPOINT" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $API_KEY" \
  -d @-
fi
