PHP-based Brown Dust 2 Gacha Simulator v1.0.0. Features implemented so far: 10-pull summoning, 5-star pity system, and targeted 5-star rate-up.
<br>The targeted rate-up supports fuzzy search. Only Simplified Chinese is supported at present; Traditional Chinese, English, Japanese and other languages will be available later.
<br>基于PHP的《棕色尘埃2》（Brown Dust 2），抽卡模拟器1.0.0，目前已实现十连抽卡、五星保底、指定五星up。
<br>指定up可模糊搜索，目前仅支持简体中文，未来会支持繁中、英语、日语等。
<br>调用示例：
```bash
/index.php?user_id=100001&up_star=star5&up_file=奇迹海洋
```
<img width="1743" height="982" alt="image" src="https://github.com/user-attachments/assets/58c1dc52-6915-4c4d-abda-52e348b245ea" />
<br>可使用BOT在QQ群等平台使用。
<br>在<a href="https://github.com/Sora233/DDBOT">DDBOT</a>项目中使用示例模板
<br>

```bash
{{ if .args }}{{ $0 := index .member_code }}{{ $1 := index .args 0 }}
{{- printf "/index.php?user_id=%v&up_star=star5&up_file=%v" $0 $1  | pic }}
{{- else -}}
{{ $0 := index .member_code }}
{{ printf "/index.php?user_id=%v" $0 | pic }}
{{- end -}}
```
<img width="854" height="531" alt="image" src="https://github.com/user-attachments/assets/afe1d68e-d5e2-4436-af3a-8b0a77d5e4d8" />
