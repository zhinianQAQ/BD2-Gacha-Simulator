基于PHP的《棕色尘埃2》抽卡模拟器1.0.0，目前已实现十连抽卡、五星保底、指定五星up。
<br>指定up可模糊搜索。
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
{{- printf "http://zhinian/ck/bd2/index.php?user_id=%v&up_star=star5&up_file=%v" $0 $1  | pic }}
{{- else -}}
{{ $0 := index .member_code }}
{{ printf "http://zhinian/ck/bd2/index.php?user_id=%v" $0 | pic }}
{{- end -}}
```
