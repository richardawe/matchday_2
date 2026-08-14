export default function robots(){return{rules:{userAgent:"*",allow:"/",disallow:["/admin/","/api/"]},sitemap:`${process.env.PUBLIC_BASE_URL||"https://matchday.example.com"}/sitemap.xml`}}
