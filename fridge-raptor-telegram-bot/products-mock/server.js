const express = require("express");

const app = express();
app.use(express.json());

let idSeq = 1000;
const products = [
  { id: 1, user_id: 111111, name: "свинина", quantity: 500, unit: "г" },
  { id: 2, user_id: 111111, name: "лук", quantity: 2, unit: "шт" },
  { id: 3, user_id: 111111, name: "картошка", quantity: 100, unit: "г" },
];

app.get("/health", (_req, res) => {
  res.json({
    status: "ok",
    service: "products-mock",
    timestamp: new Date().toISOString(),
  });
});

app.get("/api/v1/products", (req, res) => {
  const userId = Number(req.query.user_id ?? 0);
  const data = userId
    ? products.filter((item) => item.user_id === userId)
    : products;
  res.json({ success: true, data });
});

app.post("/api/v1/products", (req, res) => {
  const { user_id, name, quantity, unit } = req.body ?? {};
  if (!user_id || !name) {
    return res.status(422).json({
      success: false,
      message: "user_id and name are required",
    });
  }

  const product = {
    id: ++idSeq,
    user_id: Number(user_id),
    name: String(name),
    quantity: Number(quantity ?? 1),
    unit: String(unit ?? "шт"),
  };
  products.push(product);

  return res.status(201).json({ success: true, data: product });
});

app.delete("/api/v1/products/:id", (req, res) => {
  const id = Number(req.params.id);
  const index = products.findIndex((item) => item.id === id);
  if (index < 0) {
    return res.status(404).json({ success: false, message: "Not found" });
  }
  products.splice(index, 1);
  return res.status(204).send();
});

const port = Number(process.env.PORT ?? 8090);
app.listen(port, () => {
  console.log(`products-mock listening on ${port}`);
});
